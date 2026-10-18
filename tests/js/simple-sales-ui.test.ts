import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const vendre = readFileSync(
    new URL('../../resources/js/pages/simple/Vendre.vue', import.meta.url),
    'utf8',
);
const caisse = readFileSync(
    new URL('../../resources/js/pages/simple/Caisse.vue', import.meta.url),
    'utf8',
);

void test('Vendre expose le parcours article, panier, paiement et ticket', () => {
    assert.match(vendre, /<CarteArticle/);
    assert.match(vendre, /function ajouterArticle/);
    assert.match(vendre, /function validerVente/);
    assert.match(vendre, /router\.post\(['"]\/simple\/vendre['"]/);
    assert.match(vendre, /@secondaire="ouvrirTicket"/);
    assert.match(vendre, /saleResult\.ticket_url/);
    assert.doesNotMatch(vendre, /en cours de construction/i);
});

void test('Caisse utilise la route liée à la caisse et le contrat du résumé', () => {
    assert.match(
        caisse,
        /`\/simple\/caisse\/\$\{props\.register\.id\}\/ouvrir`/,
    );
    assert.match(caisse, /summary\.incomes/);
    assert.match(caisse, /summary\.expenses/);
    assert.doesNotMatch(caisse, /summary\.income[^s]/);
    assert.doesNotMatch(caisse, /summary\.expense[^s]/);
});

void test('Caisse accepte les grands montants et la justification des écarts', () => {
    assert.match(caisse, /inputmode="numeric"/);
    assert.match(caisse, /v-model="closeForm\.note"/);
    assert.match(caisse, /ecartDeCaisse/);
});
