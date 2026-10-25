import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { orderTotal } from '../../resources/js/lib/purchaseOrder.ts';

/*
 * « Commander » du mode simple : le bouton de la liste mène au formulaire
 * simple (plus à l'écran expert), réservé à qui peut créer et confirmer.
 */
const achats = readFileSync('resources/js/pages/simple/Achats.vue', 'utf8');
const commande = readFileSync(
    'resources/js/pages/simple/AchatCommande.vue',
    'utf8',
);

void test('le total additionne quantité × prix d’achat de chaque ligne', () => {
    assert.equal(orderTotal([]), 0);
    assert.equal(
        orderTotal([
            { productId: 'a', quantity: 10, unitCost: 8500 },
            { productId: 'b', quantity: 5, unitCost: 1200 },
        ]),
        91000,
    );
});

void test('le total ignore les quantités ou prix négatifs', () => {
    assert.equal(
        orderTotal([{ productId: 'a', quantity: -3, unitCost: 500 }]),
        0,
    );
    assert.equal(
        orderTotal([{ productId: 'a', quantity: 3, unitCost: -500 }]),
        0,
    );
});

void test('les deux boutons « Commander » mènent au formulaire simple, selon le droit du serveur', () => {
    assert.doesNotMatch(achats, /\/purchases\/orders\/create/);
    assert.equal(
        (achats.match(/href="\/simple\/achats\/commander"/g) ?? []).length,
        2,
    );
    assert.match(achats, /can: \{ order: boolean \}/);
    assert.match(achats, /computed\(\(\) => props\.can\.order\)/);
});

void test('le formulaire envoie une clé unique et des quantités entières en chaîne', () => {
    assert.match(commande, /const cle = crypto\.randomUUID\(\)/);
    assert.match(commande, /router\.post\(\s*'\/simple\/achats'/);
    assert.match(commande, /idempotency_key: cle/);
    assert.match(commande, /quantity: String\(l\.quantity\)/);
    assert.match(commande, /unit_cost: l\.unitCost/);
});

void test('on ne commande pas sans fournisseur, sans article ni sans prix d’achat', () => {
    assert.match(commande, /fournisseur\.value !== null/);
    assert.match(commande, /lignes\.value\.length > 0/);
    assert.match(commande, /!prixManquant\.value/);
    assert.match(commande, /Indiquez le prix d’achat de chaque article/);
});

void test('le total affiché est dit « hors taxe » : le serveur ajoute les taxes', () => {
    assert.match(commande, /Total hors taxe/);
});

void test('la page a son texte d’aide', () => {
    const dictionnaire = readFileSync('resources/js/i18n/fr.ts', 'utf8');

    assert.match(dictionnaire, /'simple\/AchatCommande':/);
});
