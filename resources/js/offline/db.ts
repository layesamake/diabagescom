/**
 * Accès minimal à IndexedDB (sans dépendance), une base par utilisateur et
 * entreprise. Ne contient jamais de mot de passe ni de jeton : seulement le
 * catalogue du dépôt, les clients, la file de commandes et des métadonnées.
 */
export type StoreName = 'meta' | 'products' | 'customers' | 'commands';

const VERSION = 1;

export function databaseName(
    userId: string | number,
    tenantId: string,
): string {
    return `diaba:${userId}:${tenantId}`;
}

export function openDatabase(name: string): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(name, VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            db.createObjectStore('meta', { keyPath: 'key' });
            db.createObjectStore('products', { keyPath: 'id' });
            db.createObjectStore('customers', { keyPath: 'id' });
            const commands = db.createObjectStore('commands', {
                keyPath: 'command_id',
            });
            commands.createIndex('created_at', 'created_at');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function done<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function finished(tx: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

export function getAll<T>(db: IDBDatabase, store: StoreName): Promise<T[]> {
    return done(db.transaction(store).objectStore(store).getAll()) as Promise<
        T[]
    >;
}

export async function get<T>(
    db: IDBDatabase,
    store: StoreName,
    key: string,
): Promise<T | undefined> {
    return (await done(db.transaction(store).objectStore(store).get(key))) as
        | T
        | undefined;
}

export async function put(
    db: IDBDatabase,
    store: StoreName,
    values: object[],
): Promise<void> {
    const tx = db.transaction(store, 'readwrite');
    const objects = tx.objectStore(store);
    values.forEach((value) => objects.put(value));
    await finished(tx);
}

export async function remove(
    db: IDBDatabase,
    store: StoreName,
    keys: string[],
): Promise<void> {
    const tx = db.transaction(store, 'readwrite');
    const objects = tx.objectStore(store);
    keys.forEach((key) => objects.delete(key));
    await finished(tx);
}

export async function clearStore(
    db: IDBDatabase,
    store: StoreName,
): Promise<void> {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).clear();
    await finished(tx);
}

export async function getMeta<T>(
    db: IDBDatabase,
    key: string,
): Promise<T | undefined> {
    return (await get<{ key: string; value: T }>(db, 'meta', key))?.value;
}

export function setMeta(
    db: IDBDatabase,
    key: string,
    value: unknown,
): Promise<void> {
    return put(db, 'meta', [{ key, value }]);
}

export function deleteDatabase(name: string): Promise<void> {
    return new Promise((resolve) => {
        const request = indexedDB.deleteDatabase(name);
        request.onsuccess = () => resolve();
        request.onerror = () => resolve();
        request.onblocked = () => resolve();
    });
}
