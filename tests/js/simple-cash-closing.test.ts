import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { closingResult } from '../../resources/js/lib/cashClosing.ts';

/*
 * Résultat de la fermeture de caisse (maquette 06d) : juste, manque ou trop.
 */
void test('un manque : fond rouge, « Il manque 7 000 F », compté et attendu (maquette 06d)', () => {
    const resultat = closingResult({
        expected: 47000,
        counted: 40000,
        difference: -7000,
    });

    assert.equal(resultat.etat, 'probleme');
    assert.equal(resultat.pictogramme, 'alerte');
    assert.equal(resultat.titre, 'Il manque 7 000 F');
    assert.equal(resultat.detail, 'Compté : 40 000 F · Attendu : 47 000 F');
});

void test('un excédent : fond rouge aussi, « Il y a 2 000 F en trop »', () => {
    const resultat = closingResult({
        expected: 10000,
        counted: 12000,
        difference: 2000,
    });

    assert.equal(resultat.etat, 'probleme');
    assert.equal(resultat.pictogramme, 'alerte');
    assert.equal(resultat.titre, 'Il y a 2 000 F en trop');
});

void test('une caisse juste : fond vert, coche, « C’est juste »', () => {
    const resultat = closingResult({
        expected: 14800,
        counted: 14800,
        difference: 0,
    });

    assert.equal(resultat.etat, 'reussi');
    assert.equal(resultat.pictogramme, 'coche');
    assert.equal(resultat.titre, 'C’est juste');
    assert.equal(resultat.detail, 'Compté : 14 800 F · Attendu : 14 800 F');
});

void test('les montants sont des francs entiers, jamais « XOF », sans signe moins', () => {
    const resultat = closingResult({
        expected: 5000,
        counted: 3500,
        difference: -1500,
    });

    assert.match(resultat.titre, /^Il manque 1 500 F$/);
    assert.doesNotMatch(`${resultat.titre} ${resultat.detail}`, /XOF|-|−/);
});

void test('le serveur renvoie le résultat une seule fois et Caisse l’affiche avec « Terminer »', () => {
    const controleur = readFileSync(
        'app/Http/Controllers/Simple/SimpleSalesController.php',
        'utf8',
    );
    const caisse = readFileSync('resources/js/pages/simple/Caisse.vue', 'utf8');
    const resultat = readFileSync(
        'resources/js/components/simple/EcranResultat.vue',
        'utf8',
    );

    assert.match(controleur, /Inertia::flash\('cash_closed', \[/);
    assert.match(controleur, /'difference' => \$closed->difference/);
    assert.match(caisse, /\?\.cash_closed/);
    assert.match(caisse, /<EcranResultat\s+v-if="fermeture"/);
    assert.match(caisse, /action-principale="Terminer"/);
    assert.match(resultat, /CircleAlert/);
});
