import { reactive } from 'vue';
import { lineAmount } from '@/lib/money';
import {
    clearStore,
    databaseName,
    deleteDatabase,
    get,
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
import { decideEnqueue } from '@/offline/commandGuard';
import { clearIdentity } from '@/offline/identity';
import { SyncContextRegistry, isSameBase } from '@/offline/syncContext';
import type { SyncContext } from '@/offline/syncContext';
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

/**
 * Contexte de synchronisation (audit 2026-10-15, « Contamination IndexedDB
 * lors d'un changement de tenant ») : `syncNow`/`pushQueue`/`pullCatalog`
 * capturent toujours leur contexte au démarrage et n'écrivent que sur
 * `context.db`, jamais sur les variables `db`/`scope`/`deviceKey` ci-dessus
 * qui peuvent déjà pointer vers une autre entreprise à la fin de l'appel.
 */
const contextRegistry = new SyncContextRegistry<IDBDatabase>();

function isCurrentContext(context: SyncContext<IDBDatabase>): boolean {
    return contextRegistry.isCurrent(context);
}

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
    deviceKeyOverride?: string | null,
): Promise<Response> {
    const key = deviceKeyOverride !== undefined ? deviceKeyOverride : deviceKey;

    return fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(key ? { 'X-Device-Key': key } : {}),
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

    const sameBase = isSameBase(scope, next);
    scope = next;

    if (!sameBase) {
        const nextDb = await openDatabase(
            databaseName(next.userId, next.tenantId),
        );
        let nextDeviceKey =
            (await getMeta<string>(nextDb, 'device_key')) ?? null;

        if (!nextDeviceKey) {
            nextDeviceKey = crypto.randomUUID();
            await setMeta(nextDb, 'device_key', nextDeviceKey);
        }

        // Une vente « en cours d'envoi » lors d'une fermeture brutale repart en file.
        const interrupted = (
            await getAll<LocalCommand>(nextDb, 'commands')
        ).filter((c) => c.status === 'sending');
        await put(
            nextDb,
            'commands',
            interrupted.map((c) => ({ ...c, status: 'queued' })),
        );

        // Capture un contexte immuable pour cette base et abandonne l'ancien :
        // une opération de push/pull encore en cours pour l'ancienne entreprise
        // se requeue toute seule sur SA base (jamais sur `nextDb`) avant qu'on
        // ne ferme sa connexion ci-dessous.
        const { previous } = contextRegistry.start(nextDb, next, nextDeviceKey);
        db = nextDb;
        deviceKey = nextDeviceKey;

        if (previous) {
            await previous.inFlight.catch(() => undefined);
            previous.db.close();
        }
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

async function ensureDevice(
    context: SyncContext<IDBDatabase>,
): Promise<boolean> {
    if (!context.deviceKey) {
        return false;
    }

    if (await getMeta<boolean>(context.db, 'device_registered')) {
        return true;
    }

    const response = await api(
        registerRoute().url,
        {
            method: 'POST',
            body: JSON.stringify({
                device_key: context.deviceKey,
                label: deviceLabel(),
            }),
            signal: context.controller.signal,
        },
        context.deviceKey,
    );

    if (!response.ok) {
        if (isCurrentContext(context)) {
            syncState.problem = response.status === 403 ? 'device' : 'session';
        }

        return false;
    }

    await setMeta(context.db, 'device_registered', true);

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
 *
 * Capture le contexte (base, entreprise, jeton d'appareil) une fois au
 * démarrage : `pushQueue`/`pullCatalog` n'écrivent plus jamais que sur
 * `context.db`, jamais sur les variables mutables `db`/`scope` qui peuvent
 * avoir déjà basculé vers une autre entreprise à la fin de l'appel réseau
 * (audit 2026-10-15, « Contamination IndexedDB lors d'un changement de
 * tenant »).
 */
export async function syncNow(): Promise<void> {
    const context = contextRegistry.current();

    if (!context || syncState.syncing || !navigator.onLine) {
        syncState.online = navigator.onLine;

        return;
    }

    syncState.syncing = true;

    const operation = (async () => {
        try {
            if (!(await ensureDevice(context))) {
                return;
            }

            await pushQueue(context);
            await pullCatalog(context);

            if (isCurrentContext(context)) {
                syncState.problem = null;
            }
        } catch {
            // Réseau coupé, ou contexte abandonné par un changement de
            // tenant : la file reste en l'état sur SA base, nouvel essai
            // plus tard par le contexte qui la possède.
            if (isCurrentContext(context)) {
                syncState.online = navigator.onLine;
            }
        } finally {
            if (isCurrentContext(context)) {
                syncState.syncing = false;
                await refreshCounts();
            }
        }
    })();

    context.inFlight = operation;
    await operation;
}

async function pushQueue(context: SyncContext<IDBDatabase>): Promise<void> {
    const queued = (await getAll<LocalCommand>(context.db, 'commands'))
        .filter((c) => c.status === 'queued')
        .sort((a, b) => a.created_at.localeCompare(b.created_at));

    for (let i = 0; i < queued.length; i += 20) {
        const batch = queued.slice(i, i + 20).map((c) => ({
            ...c,
            status: 'sending' as const,
            attempts: c.attempts + 1,
        }));
        await put(context.db, 'commands', batch);

        const response = await api(
            commandsRoute().url,
            {
                method: 'POST',
                body: JSON.stringify({
                    commands: batch.map((c) => ({
                        command_id: c.command_id,
                        type: c.type,
                        created_at: c.created_at,
                        payload: c.payload,
                    })),
                }),
                signal: context.controller.signal,
            },
            context.deviceKey,
        ).catch((error) => {
            // Coupure réseau ou tenant changé en cours de route (le fetch est
            // abandonné via `context.controller`) : la commande repart en
            // file sur SA propre base, jamais « sending » indéfiniment.
            void put(
                context.db,
                'commands',
                batch.map((c) => ({ ...c, status: 'queued' })),
            );
            throw error;
        });

        if (!response.ok) {
            await put(
                context.db,
                'commands',
                batch.map((c) => ({ ...c, status: 'queued' })),
            );

            if (isCurrentContext(context)) {
                syncState.problem =
                    response.status === 403
                        ? 'device'
                        : response.status === 401 || response.status === 419
                          ? 'session'
                          : 'subscription';
            }

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
            context.db,
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

async function pullCatalog(context: SyncContext<IDBDatabase>): Promise<void> {
    if (!context.scope.warehouseId) {
        return;
    }

    const warehouse = context.scope.warehouseId;
    const since = await getMeta<string>(context.db, `cursor:${warehouse}`);
    // Le catalogue arrive par pages : le curseur de synchronisation est celui de la
    // première page (le plus ancien) et n'est enregistré qu'une fois TOUTES les pages
    // reçues. Une synchronisation interrompue ne perd donc rien : elle recommence.
    let afterProducts: string | null = null;
    let afterCustomers: string | null = null;
    let cursor: string | null = null;
    let stockSnapshot: Record<string, string> | null = null;

    for (;;) {
        const response = await api(
            catalogRoute({
                query: {
                    warehouse,
                    ...(since ? { since } : {}),
                    ...(afterProducts ? { after_products: afterProducts } : {}),
                    ...(afterCustomers
                        ? { after_customers: afterCustomers }
                        : {}),
                },
            }).url,
            { signal: context.controller.signal },
            context.deviceKey,
        );

        if (!response.ok) {
            if (isCurrentContext(context)) {
                syncState.problem =
                    response.status === 403 ? 'device' : 'session';
            }

            return;
        }

        const data = (await response.json()) as {
            cursor: string;
            full: boolean;
            first_page: boolean;
            has_more: boolean;
            next_after_products: string | null;
            next_after_customers: string | null;
            products: (Omit<
                OfflineProduct,
                'search' | 'standard_cost' | 'available'
            > & { removed: boolean })[];
            customers: (Omit<OfflineCustomer, 'search'> & {
                removed: boolean;
            })[];
            stock: Record<string, string> | null;
            sessions: unknown[] | null;
        };

        if (data.first_page) {
            cursor = data.cursor;
            stockSnapshot = data.stock;

            if (data.full) {
                await clearStore(context.db, 'products');
                await clearStore(context.db, 'customers');
            }
        }

        await remove(
            context.db,
            'products',
            data.products.filter((p) => p.removed).map((p) => p.id),
        );
        await put(
            context.db,
            'products',
            data.products
                .filter((p) => !p.removed)
                .map((p) => ({
                    ...p,
                    standard_cost: null,
                    search: normalize(
                        `${p.name} ${p.sku} ${p.barcodes.join(' ')}`,
                    ),
                })),
        );
        await remove(
            context.db,
            'customers',
            data.customers.filter((c) => c.removed).map((c) => c.id),
        );
        await put(
            context.db,
            'customers',
            data.customers
                .filter((c) => !c.removed)
                .map((c) => ({
                    ...c,
                    search: normalize(`${c.name} ${c.code} ${c.phone ?? ''}`),
                })),
        );

        if (!data.has_more) {
            break;
        }

        afterProducts = data.next_after_products;
        afterCustomers = data.next_after_customers;
    }

    if (cursor === null || stockSnapshot === null) {
        return;
    }

    await setMeta(context.db, `stock:${warehouse}`, stockSnapshot);
    await setMeta(context.db, `cursor:${warehouse}`, cursor);
    await setMeta(
        context.db,
        `synced_at:${warehouse}`,
        new Date().toISOString(),
    );

    // Le cache mémoire `stock` et `syncState.lastSync` ne représentent que
    // l'entreprise active : jamais mis à jour avec la réponse d'un contexte
    // devenu obsolète (changement de tenant pendant l'appel).
    if (isCurrentContext(context)) {
        syncState.lastSync = new Date().toISOString();
        await loadStock();
    }
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

    return (
        (await getAll<OfflineProduct>(db, 'products'))
            .filter(
                (p) =>
                    (!stockedOnly || p.type !== 'service') &&
                    p.search.includes(needle),
            )
            .sort((a, b) => a.name.localeCompare(b.name, 'fr'))
            // Liste complète à parcourir (champ vide) : 30 premiers ; recherche : 10.
            .slice(0, needle === '' ? 30 : 10)
            .map(toPicked)
    );
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
        .sort((a, b) => a.name.localeCompare(b.name, 'fr'))
        .slice(0, needle === '' ? 30 : 10);
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

    if (commandId !== undefined) {
        // Jamais d'écrasement silencieux d'une vente déjà en file (même identifiant).
        const existing = await get<LocalCommand>(db, 'commands', commandId);
        const decision = decideEnqueue(existing, payload);

        if (decision === 'reuse' && existing) {
            return existing;
        }

        if (decision === 'conflict') {
            throw new Error('command_id_conflict');
        }
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

    const previous = contextRegistry.clear();

    if (previous) {
        await previous.inFlight.catch(() => undefined);
    }

    if (scope) {
        db?.close();
        await deleteDatabase(databaseName(scope.userId, scope.tenantId));
    }

    db = null;
    scope = null;
    deviceKey = null;
    syncState.ready = false;
    syncState.pending = 0;
    syncState.attention = 0;

    // Déclare au service worker qu'il n'y a plus de compte actif : purge
    // stricte de toute page privée mise en cache (audit 2026-10-15).
    clearIdentity();

    if ('caches' in window) {
        await Promise.all(
            (await caches.keys()).map((key) => caches.delete(key)),
        );
    }
}
