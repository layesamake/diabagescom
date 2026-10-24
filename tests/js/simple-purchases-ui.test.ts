import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = 'resources/js/pages/simple/Achats.vue';

void test('la page Achats existe', () => {
    assert.ok(existsSync(page), `${page} introuvable`);
});

void test('Achats presente les commandes en route et les commandes recues', () => {
    const src = readFileSync(page, 'utf8');

    assert.match(src, /<EnTeteEcran\b/);
    assert.match(src, /titre="Achats"/);
    assert.match(src, /code-audio="audio_code"/);
    assert.match(src, /open_orders/);
    assert.match(src, /received_orders/);
    assert.match(src, /Commandes en route/);
    assert.match(src, /Commandes reçues/);
    assert.match(src, /Commande en route/);
    assert.match(src, /Commande reçue/);
});

void test('Achats ne propose une nouvelle commande quavec la permission metier', () => {
    const src = readFileSync(page, 'utf8');

    assert.match(src, /can\('purchase\.create'\)/);
    assert.match(src, /href="\/purchases\/orders\/create"/);
    assert.match(src, /Commander/);
});
