import { reactive } from 'vue';
import { lineAmount } from '@/lib/money';
import {
    clearStore,
    databaseName,
    deleteDatabase,
    getAll,
    getMeta,
    openDatabase,
    put,
    remove,
    setMeta,
} from '@/offline/db';
import {
    catalog as catalogRoute,
    commands as commandsRoute,
} from '@/routes/sync';
import { register as registerRoute } from '@/routes/sync/devices';
import type { PickedProduct } from '@/types/catalog';

/**
 * Fonctionnement hors connexion (docs/08).
 *
 * - Les ventes préparées hors ligne sont des commandes immuables en file locale
 *   (IndexedDB), persistantes après redémarrage, envoyées dans l'ordre.
 * - Le serveur décide seul : tant qu'il n'a pas répondu, une vente reste
 *   « provisoire ». Un renvoi (coupure pendant l'envoi) est sans risque : le
 *   serveur reconnaît la commande et renvoie son résultat d'origine.
 */
export type CommandStatus =
    | 'queued'
    | 'sending'
    | 'accepted'
    | 'rejected'
    | 'conflict'
    | 'requires_review'
    | 'discarded';

export type SaleLinePayload = {
    product_id: string;
    quantity: string;
    unit_price: number;
    discount: number;
};

export type SalePayload = {
    warehouse_id: string;
    cash_session_id: string;
    customer_id: string | null;
    negative_stock_reason: string | null;
    lines: SaleLinePayload[];
    tenders: { method: string; amount: number; reference: string | null }[];
};

export type LocalCommand = {
    command_id: string;
    type: 'sale';
    created_at: string;
    payload: SalePayload;
    status: CommandStatus;
    summary: { total: number; labels: string[]; customer: string | null };
    result?: {
        document_id?: string;
        number?: string;
        total?: number;
        issues?: unknown[];
    } | null;
    error?: { code: string; message: string } | null;
    attempts: number;
};

export type OfflineProduct = PickedProduct & {
    barcodes: string[];
    search: string;
};

export type OfflineCustomer = {
    id: string;
    code: string;
    name: string;
    phone: string | null;
    credit_limit: number | null;
    outstanding: number;
    search: string;
};

type Scope = {
    userId: string | number;
    tenantId: string;
    warehouseId: string | null;
};

export const syncState = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    syncing: false,
    pending: 0,
    attention: 0,
    lastSync: null as string | null,
    problem: null as 'session' | 'device' | 'subscription' | null,
    ready: false,
});

let db: IDBDatabase | null = null;
let scope: Scope | null = null;
let deviceKey: string | null = null;
let timer: ReturnType<typeof setInterval> | undefined;
let listening = false;
const stock = new Map<string, string>();

export function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function api(
    url: string,
    init: Omit<RequestInit, 'headers'> & {
        headers?: Record<string, string>;
    } = {},
): Promise<Response> {
    return fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(deviceKey ? { 'X-Device-Key': deviceKey } : {}),
            ...init.headers,
        },
    });
}

async function refreshCounts(): Promise<void> {
    if (!db) {
        return;
    }

    const commands = await getAll<LocalCommand>(db, 'commands');
    syncState.pending = commands.filter(
        (c) => c.status === 'queued' || c.status === 'sending',
    ).length;
    syncState.attention = commands.filter((c) =>
        ['rejected', 'conflict', 'requires_review'].includes(c.status),
    ).length;
}

/**
 * Prépare l'appareil pour l'utilisateur et l'entreprise courants. Appelé au
 * chargement de l'application et à chaque changement d'entreprise ou de dépôt.
 */
export async function initOffline(next: Scope): Promise<void> {
    if (typeof indexedDB === 'undefined') {
        return;
    }

    const sameBase =
        scope &&
        scope.userId === next.userId &&
        scope.tenantId === next.tenantId;
    scope = next;

    if (!sameBase) {
        db?.close();
        db = await openDatabase(databaseName(next.userId, next.tenantId));
        deviceKey = (await getMeta<string>(db, 'device_key')) ?? null;

        if (!deviceKey) {
            deviceKey = crypto.randomUUID();
            await setMeta(db, 'device_key', deviceKey);
        }

        // Une vente « en cours d'envoi » lors d'une fermeture brutale repart en file.
        const interrupted = (await getAll<LocalCommand>(db, 'commands')).filter(
            (c) => c.status === 'sending',
        );
        await put(
            db,
            'commands',
            interrupted.map((c) => ({ ...c, status: 'queued' })),
        );
    }

    syncState.lastSync = next.warehouseId
        ? ((await getMeta<string>(db!, `synced_at:${next.warehouseId}`)) ??
          null)
        : null;
    await loadStock();
    await refreshCounts();
    syncState.ready = true;

    if (!listening) {
        listening = true;
        window.addEventListener('online', () => {
            syncState.online = true;
            void syncNow();
        });
        window.addEventListener('offline', () => (syncState.online = false));
        timer = setInterval(() => void syncNow(), 60_000);
    }

    void syncNow();
}

async function loadStock(): Promise<void> {
    stock.clear();

    if (db && scope?.warehouseId) {
        const saved =
            (await getMeta<Record<string, string>>(
                db,
                `stock:${scope.warehouseId}`,
            )) ?? {};
        Object.entries(saved).forEach(([id, qty]) => stock.set(id, qty));
    }
}

async function ensureDevice(): Promise<boolean> {
    if (!db || !deviceKey) {
        return false;
    }

    if (await getMeta<boolean>(db, 'device_registered')) {
        return true;
    }

    const response = await api(registerRoute().url, {
        method: 'POST',
        body: JSON.stringify({ device_key: deviceKey, label: deviceLabel() }),
    });

    if (!response.ok) {
        syncState.problem = response.status === 403 ? 'device' : 'session';

        return false;
    }

    await setMeta(db, 'device_registered', true);

    return true;
}

function deviceLabel(): string {
    const ua = navigator.userAgent;
    const platform = /Android/i.test(ua)
        ? 'Android'
        : /iPhone|iPad/i.test(ua)
          ? 'iOS'
          : /Windows/i.test(ua)
            ? 'Windows'
            : /Mac/i.test(ua)
              ? 'Mac'
              : 'Navigateur';

    return `${platform} · ${new Date().toLocaleDateString('fr-FR')}`;
}

/**
 * Envoie la file puis met à jour le catalogue. Sans effet hors ligne.
 */
export async function syncNow(): Promise<void> {
    if (!db || !scope || syncState.syncing || !navigator.onLine) {
        syncState.online = navigator.onLine;

        return;
    }

    syncState.syncing = true;

    try {
        if (!(await ensureDevice())) {
            return;
        }

        await pushQueue();
        await pullCatalog();
        syncState.problem = null;
    } catch {
        // Réseau coupé pendant l'échange : la file est conservée, nouvel essai plus tard.
        syncState.online = navigator.onLine;
    } finally {
        syncState.syncing = false;
        await refreshCounts();
    }
}

async function pushQueue(): Promise<void> {
    if (!db) {
        return;
    }

    const queued = (await getAll<LocalCommand>(db, 'commands'))
        .filter((c) => c.status === 'queued')
        .sort((a, b) => a.created_at.localeCompare(b.created_at));

    for (let i = 0; i < queued.length; i += 20) {
        const batch = queued.slice(i, i + 20).map((c) => ({
            ...c,
            status: 'sending' as const,
            attempts: c.attempts + 1,
        }));
        await put(db, 'commands', batch);

        const response = await api(commandsRoute().url, {
            method: 'POST',
            body: JSON.stringify({
                commands: batch.map((c) => ({
                    command_id: c.command_id,
                    type: c.type,
                    created_at: c.created_at,
                    payload: c.payload,
                })),
            }),
        }).catch((error) => {
            void put(
                db!,
                'commands',
                batch.map((c) => ({ ...c, status: 'queued' })),
            );
            throw error;
        });

        if (!response.ok) {
            await put(
                db,
                'commands',
                batch.map((c) => ({ ...c, status: 'queued' })),
            );
            syncState.problem =
                response.status === 403
                    ? 'device'
                    : response.status === 401 || response.status === 419
                      ? 'session'
                      : 'subscription';

            return;
        }

        const { results } = (await response.json()) as {
            results: {
                command_id: string;
                status: string;
                result: LocalCommand['result'];
                error: LocalCommand['error'];
            }[];
        };
        const byId = new Map(results.map((r) => [r.command_id, r]));

        await put(
            db,
            'commands',
            batch.map((c) => {
                const r = byId.get(c.command_id);

                if (!r || r.status === 'deferred') {
                    return { ...c, status: 'queued', error: r?.error ?? null };
                }

                return {
                    ...c,
                    status: r.status as CommandStatus,
                    result: r.result,
                    error: r.error,
                };
            }),
        );
    }
}

async function pullCatalog(): Promise<void> {
    if (!db || !scope?.warehouseId) {
        return;
    }

    const warehouse = scope.warehouseId;
    const since = await getMeta<string>(db, `cursor:${warehouse}`);
    const response = await api(
        catalogRoute({ query: { warehouse, ...(since ? { since } : {}) } }).url,
    );

    if (!response.ok) {
        syncState.problem = response.status === 403 ? 'device' : 'session';

        return;
    }

    const data = (await response.json()) as {
        cursor: string;
        full: boolean;
        products: (Omit<
            OfflineProduct,
            'search' | 'standard_cost' | 'available'
        > & { removed: boolean })[];
        customers: (Omit<OfflineCustomer, 'search'> & { removed: boolean })[];
        stock: Record<string, string>;
        sessions: unknown[];
    };

    if (data.full) {
        await clearStore(db, 'products');
        await clearStore(db, 'customers');
    }

    await remove(
        db,
        'products',
        data.products.filter((p) => p.removed).map((p) => p.id),
    );
    await put(
        db,
        'products',
        data.products
            .filter((p) => !p.removed)
            .map((p) => ({
                ...p,
                standard_cost: null,
                search: normalize(`${p.name} ${p.sku} ${p.barcodes.join(' ')}`),
            })),
    );
    await remove(
        db,
        'customers',
        data.customers.filter((c) => c.removed).map((c) => c.id),
    );
    await put(
        db,
        'customers',
        data.customers
            .filter((c) => !c.removed)
            .map((c) => ({
                ...c,
                search: normalize(`${c.name} ${c.code} ${c.phone ?? ''}`),
            })),
    );
    await setMeta(db, `stock:${warehouse}`, data.stock);
    await setMeta(db, `cursor:${warehouse}`, data.cursor);
    await setMeta(db, `synced_at:${warehouse}`, new Date().toISOString());
    syncState.lastSync = new Date().toISOString();
    await loadStock();
}

function toPicked(product: OfflineProduct): PickedProduct {
    return {
        ...product,
        available:
            product.type === 'service' ? null : (stock.get(product.id) ?? '0'),
    };
}

/** Recherche locale (nom, référence, code-barres), insensible aux accents. */
export async function searchProductsOffline(
    query: string,
    stockedOnly = false,
): Promise<PickedProduct[]> {
    if (!db) {
        return [];
    }

    const needle = normalize(query.trim());

    return (await getAll<OfflineProduct>(db, 'products'))
        .filter(
            (p) =>
                (!stockedOnly || p.type !== 'service') &&
                p.search.includes(needle),
        )
        .slice(0, 10)
        .map(toPicked);
}

export async function lookupOffline(
    code: string,
): Promise<PickedProduct | null> {
    if (!db) {
        return null;
    }

    const wanted = code.trim().toUpperCase();
    const product = (await getAll<OfflineProduct>(db, 'products')).find(
        (p) =>
            p.sku.toUpperCase() === wanted ||
            p.barcodes.some((b) => b.toUpperCase() === wanted),
    );

    return product ? toPicked(product) : null;
}

export async function searchCustomersOffline(
    query: string,
): Promise<OfflineCustomer[]> {
    if (!db) {
        return [];
    }

    const needle = normalize(query.trim());

    return (await getAll<OfflineCustomer>(db, 'customers'))
        .filter((c) => c.search.includes(needle))
        .slice(0, 10);
}

/**
 * Met une vente en file. La clé peut être celle d'un envoi en ligne interrompu :
 * si le serveur l'avait déjà traitée, il renvoie simplement son résultat.
 */
export async function enqueueSale(
    payload: SalePayload,
    summary: LocalCommand['summary'],
    commandId?: string,
): Promise<LocalCommand> {
    if (!db) {
        throw new Error('Offline storage unavailable');
    }

    const command: LocalCommand = {
        command_id: commandId ?? crypto.randomUUID(),
        type: 'sale',
        created_at: new Date().toISOString(),
        payload,
        status: 'queued',
        summary,
        attempts: 0,
    };

    await put(db, 'commands', [command]);
    await refreshCounts();
    void syncNow();

    return command;
}

export async function listCommands(): Promise<LocalCommand[]> {
    if (!db) {
        return [];
    }

    return (await getAll<LocalCommand>(db, 'commands')).sort((a, b) =>
        b.created_at.localeCompare(a.created_at),
    );
}

/**
 * Renvoie une vente refusée sous une nouvelle commande (l'ancienne est conservée,
 * marquée abandonnée) : prix actualisés, motif de stock négatif…
 */
export async function resend(
    command: LocalCommand,
    payload: SalePayload,
): Promise<void> {
    await discard(command);
    await enqueueSale(payload, {
        ...command.summary,
        total: saleTotal(payload),
    });
}

export function saleTotal(payload: SalePayload): number {
    return payload.lines.reduce(
        (sum, l) =>
            sum +
            Math.max(0, lineAmount(l.quantity, l.unit_price) - l.discount),
        0,
    );
}

export async function discard(command: LocalCommand): Promise<void> {
    if (!db) {
        return;
    }

    await put(db, 'commands', [{ ...command, status: 'discarded' }]);
    await refreshCounts();
}

export async function currentPrices(
    productIds: string[],
): Promise<Map<string, number>> {
    const prices = new Map<string, number>();

    if (db) {
        (await getAll<OfflineProduct>(db, 'products'))
            .filter((p) => productIds.includes(p.id))
            .forEach((p) => prices.set(p.id, p.sale_price ?? 0));
    }

    return prices;
}

/**
 * Déconnexion explicite : base locale et caches effacés (docs/08). L'appelant
 * prévient avant si des ventes ne sont pas encore synchronisées.
 */
export async function clearOfflineData(): Promise<void> {
    clearInterval(timer);
    listening = false;

    if (scope) {
        db?.close();
        await deleteDatabase(databaseName(scope.userId, scope.tenantId));
    }

    db = null;
    scope = null;
    syncState.ready = false;
    syncState.pending = 0;
    syncState.attention = 0;

    if ('caches' in window) {
        await Promise.all(
            (await caches.keys()).map((key) => caches.delete(key)),
        );
    }
}
