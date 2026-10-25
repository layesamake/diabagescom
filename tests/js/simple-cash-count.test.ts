import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    bump,
    DENOMINATIONS,
    emptyCount,
    isEmpty,
    MAX_PER_DENOMINATION,
    subtotal,
    total,
} from '../../resources/js/lib/cashCount.ts';

/*
 * Comptage de l'argent du tiroir billet par billet (maquette 06b).
 */
void test('les valeurs comptées sont celles de la maquette, de la plus grande à la plus petite', () => {
    assert.deepEqual([...DENOMINATIONS], [10000, 5000, 2000, 1000, 500, 100]);
});

void test('un comptage vide vaut 0 F', () => {
    assert.equal(total(emptyCount()), 0);
    assert.equal(isEmpty(emptyCount()), true);
});

void test('le total additionne chaque valeur (maquette : 5 000 + 2 × 1 000 + 500 = 7 500 F)', () => {
    let compte = emptyCount();
    compte = bump(compte, 5000, 1);
    compte = bump(compte, 1000, 1);
    compte = bump(compte, 1000, 1);
    compte = bump(compte, 500, 1);

    assert.equal(total(compte), 7500);
    assert.equal(subtotal(compte, 1000), 2000);
    assert.equal(isEmpty(compte), false);
});

void test('on ne peut ni descendre sous 0 ni dépasser le plafond', () => {
    assert.equal(bump(emptyCount(), 500, -1)[500], 0);

    let compte = emptyCount();
    compte = bump(compte, 100, MAX_PER_DENOMINATION + 50);
    assert.equal(compte[100], MAX_PER_DENOMINATION);
});

void test('bump ne modifie pas le comptage d’origine', () => {
    const origine = emptyCount();
    bump(origine, 10000, 3);

    assert.equal(origine[10000], 0);
});

void test('la liste du navigateur est la même que celle du serveur', () => {
    const php = readFileSync('app/Domain/Cash/CashCount.php', 'utf8');
    const valeurs = php
        .match(/DENOMINATIONS = \[([^\]]+)\]/)?.[1]
        .split(',')
        .map((v) => Number(v.trim()));

    assert.deepEqual(valeurs, [...DENOMINATIONS]);
});

void test('Caisse compte l’argent billet par billet à l’ouverture comme à la fermeture', () => {
    const caisse = readFileSync('resources/js/pages/simple/Caisse.vue', 'utf8');

    assert.match(caisse, /import ComptageBillets from/);
    assert.match(caisse, /useForm\(\{ count: emptyCount\(\) \}\)/);
    assert.match(caisse, /useForm\(\{ count: emptyCount\(\), note: '' \}\)/);
    // Le bouton annonce le total compté, jamais un montant saisi.
    assert.match(caisse, /Ouvrir avec \{\{ formatFranc\(totalOuverture\) \}\}/);
    // Plus de champ de montant tapé à la main, ni à l'ouverture ni à la fermeture.
    assert.doesNotMatch(caisse, /v-model\.number/);
    assert.doesNotMatch(caisse, /<input/);
});

void test('chaque bouton du comptage fait au moins 48 px et nomme son billet', () => {
    const composant = readFileSync(
        'resources/js/components/simple/ComptageBillets.vue',
        'utf8',
    );

    assert.equal((composant.match(/size-12/g) ?? []).length, 2);
    assert.match(composant, /aria-label="`Retirer un billet de/);
    assert.match(composant, /aria-label="`Ajouter un billet de/);
});
