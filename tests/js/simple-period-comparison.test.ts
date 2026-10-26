import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    comparisonMessage,
    previousLabel,
    que,
} from '../../resources/js/lib/periodComparison.ts';

/*
 * Mes résultats (maquette 11) : « Mieux que septembre » sous « Il me reste ».
 */
void test('mois : la maquette dit « Mieux que septembre » en octobre', () => {
    assert.deepEqual(comparisonMessage('month', '2026-09-01', 4000), {
        sens: 'mieux',
        texte: 'Mieux que septembre',
    });
});

void test('moins bien et pareil, avec le même nom de période', () => {
    assert.equal(
        comparisonMessage('month', '2026-09-01', -1)?.texte,
        'Moins bien que septembre',
    );
    assert.equal(comparisonMessage('month', '2026-09-01', -1)?.sens, 'moins');
    assert.equal(
        comparisonMessage('month', '2026-09-01', 0)?.texte,
        'Pareil que septembre',
    );
    assert.equal(comparisonMessage('month', '2026-09-01', 0)?.sens, 'pareil');
});

void test('rien à comparer : aucun message (pas de vente la période d’avant, ou chiffre masqué)', () => {
    assert.equal(comparisonMessage('month', '2026-09-01', null), null);
});

void test('les mois qui commencent par une voyelle prennent « qu’ »', () => {
    assert.equal(
        comparisonMessage('month', '2026-03-01', 1)?.texte,
        'Mieux que mars',
    );
    assert.equal(
        comparisonMessage('month', '2026-04-01', 1)?.texte,
        'Mieux qu’avril',
    );
    assert.equal(
        comparisonMessage('month', '2026-07-01', 1)?.texte,
        'Mieux que juillet',
    );
    assert.equal(
        comparisonMessage('month', '2026-08-01', 1)?.texte,
        'Mieux qu’août',
    );
    assert.equal(
        comparisonMessage('month', '2026-09-01', 1)?.texte,
        'Mieux que septembre',
    );
    assert.equal(
        comparisonMessage('month', '2026-10-01', 1)?.texte,
        'Mieux qu’octobre',
    );
    assert.equal(que('janvier'), 'que janvier');
});

void test('janvier : le mois précédent est décembre de l’année d’avant', () => {
    // Le serveur envoie previous.from = 2025-12-01 quand on est en janvier 2026.
    assert.equal(previousLabel('month', '2025-12-01'), 'décembre');
    assert.equal(
        comparisonMessage('month', '2025-12-01', 100)?.texte,
        'Mieux que décembre',
    );
});

void test('semaine et année : « la semaine dernière » et l’année d’avant', () => {
    assert.equal(
        comparisonMessage('week', '2026-10-12', 5)?.texte,
        'Mieux que la semaine dernière',
    );
    assert.equal(
        comparisonMessage('year', '2025-01-01', -5)?.texte,
        'Moins bien que 2025',
    );
});

void test('la page compare « Il me reste » si on le voit, sinon « Vendu », et ne dit pas XOF', () => {
    const page = readFileSync(
        'resources/js/pages/simple/Resultats.vue',
        'utf8',
    ).replace(/\s+/g, ' ');

    assert.match(
        page,
        /props\.can\.margin && props\.summary\.remaining !== null \? props\.comparison\.margin : props\.comparison\.total/,
    );
    assert.match(page, /props\.period\.previous\.from/);
    assert.match(page, /v-if="comparaison"/);
    assert.doesNotMatch(page, /XOF/);
});
