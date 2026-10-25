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

    // Le droit est calculé par le serveur (créer ET confirmer, comme en mode expert) :
    // le bouton mène au formulaire simple, plus à l'écran expert.
    assert.match(src, /props\.can\.order/);
    assert.match(src, /href="\/simple\/achats\/commander"/);
    assert.doesNotMatch(src, /\/purchases\/orders\/create/);
    assert.match(src, /Commander/);
});

void test('le detail permet de compter les articles et de les ranger dans le stock', () => {
    const detail = readFileSync('resources/js/pages/simple/Achat.vue', 'utf8');

    assert.match(detail, /Compteur/);
    assert.match(detail, /quantites/);
    assert.match(detail, /crypto\.randomUUID\(\)/);
    assert.match(detail, /router\.post\(/);
    assert.match(detail, /\/simple\/achats\/\$\{props\.order\.id\}\/recevoir/);
    assert.match(detail, /Ranger dans le stock/);
});
