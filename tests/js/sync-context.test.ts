import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    SyncContextRegistry,
    isSameBase,
} from '../../resources/js/offline/syncContext.ts';

/*
 * Vérifie, sans IndexedDB ni réseau, les primitives qui empêchent la
 * contamination inter-tenant décrite dans l'audit 2026-10-15 (« Contamination
 * IndexedDB lors d'un changement de tenant ») : un push/pull capturé pour le
 * compte/entreprise A ne peut jamais écrire sur la base de B, et un contexte
 * abandonné est explicitement marqué comme tel (AbortController).
 */

void test('isSameBase: même compte et même entreprise uniquement', () => {
    assert.equal(
        isSameBase(
            { userId: 1, tenantId: 'a', warehouseId: null },
            { userId: 1, tenantId: 'a', warehouseId: 'w2' },
        ),
        true,
    );
    assert.equal(
        isSameBase(
            { userId: 1, tenantId: 'a', warehouseId: null },
            { userId: 1, tenantId: 'b', warehouseId: null },
        ),
        false,
    );
    assert.equal(
        isSameBase(null, { userId: 1, tenantId: 'a', warehouseId: null }),
        false,
    );
});

void test('starting a context for a different tenant aborts and replaces the previous one', () => {
    const registry = new SyncContextRegistry();
    const { context: ctxA } = registry.start('db-tenant-a', {
        userId: 1,
        tenantId: 'a',
        warehouseId: null,
    });
    assert.equal(registry.isCurrent(ctxA), true);

    const { context: ctxB, previous } = registry.start('db-tenant-b', {
        userId: 1,
        tenantId: 'b',
        warehouseId: null,
    });

    assert.equal(previous, ctxA);
    assert.equal(
        ctxA.controller.signal.aborted,
        true,
        'the stale context must be aborted so in-flight requests stop',
    );
    assert.equal(registry.isCurrent(ctxA), false);
    assert.equal(registry.isCurrent(ctxB), true);
    assert.notEqual(ctxA.db, ctxB.db);
});

void test('a write captured against a context always targets its own db, even once stale', () => {
    const registry = new SyncContextRegistry();
    const writesA = [];
    const writesB = [];
    const { context: ctxA } = registry.start(writesA, {
        userId: 1,
        tenantId: 'a',
        warehouseId: null,
    });

    // Changement de tenant pendant que la réponse du serveur pour A est encore en vol.
    registry.start(writesB, { userId: 1, tenantId: 'b', warehouseId: null });

    // Simule l'écriture tardive de la réponse reçue pour A : elle utilise
    // toujours `ctxA.db`, capturé au démarrage, jamais la base active.
    ctxA.db.push('sale-result-for-a');

    assert.deepEqual(writesA, ['sale-result-for-a']);
    assert.deepEqual(
        writesB,
        [],
        "tenant B's store must stay untouched by A's late write",
    );
});

void test('clear() aborts the active context on logout and returns it for cleanup', () => {
    const registry = new SyncContextRegistry();
    const { context } = registry.start('db', {
        userId: 1,
        tenantId: 'a',
        warehouseId: null,
    });

    const cleared = registry.clear();

    assert.equal(cleared, context);
    assert.equal(context.controller.signal.aborted, true);
    assert.equal(registry.current(), null);
});

void test('generations strictly increase across tenant switches', () => {
    const registry = new SyncContextRegistry();
    const { context: first } = registry.start('db-a', {
        userId: 1,
        tenantId: 'a',
        warehouseId: null,
    });
    const { context: second } = registry.start('db-b', {
        userId: 1,
        tenantId: 'b',
        warehouseId: null,
    });

    assert.equal(second.generation > first.generation, true);
});
