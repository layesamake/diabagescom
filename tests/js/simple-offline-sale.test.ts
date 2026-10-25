import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { buildSimpleSalePayload } from '../../resources/js/offline/simpleSale.ts';

/*
 * Vendre du mode simple sans internet (cahier des charges : « Vendre et la
 * caisse marchent sans internet et se synchronisent au retour du réseau »).
 */
const base = {
    warehouseId: '11111111-1111-4111-8111-111111111111',
    cashSessionId: '22222222-2222-4222-8222-222222222222',
    customerId: null,
    lines: [
        {
            productId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            quantity: 2,
            unitPrice: 1500,
        },
        {
            productId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            quantity: 1,
            unitPrice: 500,
        },
    ],
} as const;

void test('la commande hors ligne reprend la forme du POS expert (revalidée par le même serveur)', () => {
    const payload = buildSimpleSalePayload({ ...base, method: 'cash' });

    assert.equal(payload.warehouse_id, base.warehouseId);
    assert.equal(payload.cash_session_id, base.cashSessionId);
    assert.equal(payload.customer_id, null);
    assert.equal(payload.negative_stock_reason, null);
    assert.deepEqual(payload.lines, [
        {
            product_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            quantity: '2',
            unit_price: 1500,
            discount: 0,
        },
        {
            product_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            quantity: '1',
            unit_price: 500,
            discount: 0,
        },
    ]);
});

void test('le prix affiché est capturé sur chaque ligne et les quantités sont des chaînes', () => {
    const payload = buildSimpleSalePayload({ ...base, method: 'cash' });

    assert.ok(payload.lines.every((l) => typeof l.quantity === 'string'));
    assert.ok(payload.lines.every((l) => Number.isInteger(l.unit_price)));
});

void test('espèces et téléphone encaissent le total exact, sans référence', () => {
    for (const method of ['cash', 'wave', 'orange_money'] as const) {
        const payload = buildSimpleSalePayload({ ...base, method });

        assert.deepEqual(payload.tenders, [
            { method, amount: 3500, reference: null },
        ]);
    }
});

void test('une vente à crédit n’encaisse rien et garde le client', () => {
    const payload = buildSimpleSalePayload({
        ...base,
        customerId: '33333333-3333-4333-8333-333333333333',
        method: 'credit',
    });

    assert.deepEqual(payload.tenders, []);
    assert.equal(payload.customer_id, '33333333-3333-4333-8333-333333333333');
});

void test('Vendre garde la vente en file locale hors ligne, avec la même clé que l’envoi en ligne', () => {
    const src = readFileSync('resources/js/pages/simple/Vendre.vue', 'utf8');

    assert.match(src, /enqueueSale\(/);
    assert.match(src, /!syncState\.online \|\| !navigator\.onLine/);
    assert.match(
        src,
        /onNetworkError: \(\) => \{\s*void garderHorsLigne\(\);\s*return false;/,
    );
    // La file reçoit la clé d'idempotence : un renvoi ne crée jamais de doublon.
    assert.match(src, /cleVente\.value,\s*\);/);
    assert.match(src, /idempotency_key: cleVente\.value/);
    assert.match(src, /titre="Vente gardée"/);
});

void test('le cadre du mode simple prépare le hors ligne et montre l’état réel du réseau', () => {
    const layout = readFileSync(
        'resources/js/layouts/SimpleLayout.vue',
        'utf8',
    );

    assert.match(layout, /useOfflineStart\(\);/);
    assert.match(layout, /syncState\.online \? 'En ligne' : /);
    assert.match(layout, /syncState\.pending > 0/);
});

void test('SyncBadge et le mode simple partagent la même initialisation hors ligne', () => {
    const badge = readFileSync('resources/js/components/SyncBadge.vue', 'utf8');
    const composable = readFileSync(
        'resources/js/composables/useOfflineStart.ts',
        'utf8',
    );

    assert.match(badge, /useOfflineStart\(\);/);
    assert.doesNotMatch(badge, /initOffline\(/);
    assert.match(composable, /initOffline\(\{/);
    assert.match(composable, /can\('sale\.create'\)/);
});
