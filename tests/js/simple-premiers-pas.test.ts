import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

// L'écran Premiers pas doit reproduire la maquette 03 : pastille d'étape
// « 2 / 11 », points de progression, étapes terminées avec coche verte,
// étape courante mise en valeur par un cadre orange, étapes à venir en gris,
// pied de page « + N autres étapes ».

const page = 'resources/js/pages/simple/PremiersPas.vue';

void test('la page Premiers pas existe', () => {
    assert.ok(existsSync(page), `${page} introuvable`);
});

void test('Premiers pas utilise la pastille d etape 2 / 11', () => {
    const src = readFileSync(page, 'utf8');
    assert.match(src, /<EnTeteEcran\b/, 'EnTeteEcran utilisé');
    assert.match(src, /titre="Premiers pas"/, 'titre attendu');
    assert.match(src, /:etape="2"/, 'etape=2');
    assert.match(src, /:etape-total="11"/, 'etapeTotal=11');
});

void test('la barre de progression contient 11 points', () => {
    const src = readFileSync(page, 'utf8');
    const region = src.match(/<BarreEtapes\b[\s\S]*?\/>/);
    assert.ok(region, 'BarreEtapes attendue');
    // 1 étape faite + 1 en cours sur 11 au total
    assert.match(region[0], /total="11"/);
    assert.match(region[0], /faites="1"/);
    assert.match(region[0], /:en-cours="true"|enCours="true"|en-cours/);
});

void test('Premiers pas liste les 11 etapes de la maquette', () => {
    const src = readFileSync(page, 'utf8');
    const titres = [
        'Mon entreprise',
        'Enregistrer la voix',
        'Compter mon stock',
        'Ouvrir la caisse',
        'Faire une vente',
    ];
    for (const titre of titres) {
        assert.ok(
            src.includes(titre),
            `étape attendue dans la page : ${titre}`,
        );
    }
    assert.match(src, /autres étapes/);
});

void test('Premiers pas enregistre la voix comme etape courante', () => {
    const src = readFileSync(page, 'utf8');
    // l'étape courante est mise en valeur (bordure orange + badge Chef + bouton vert)
    assert.match(src, /--s-orange/, 'cadre orange attendu');
    assert.match(src, /Chef/, 'badge Chef attendu');
    assert.match(src, /Commencer/, 'bouton Commencer attendu');
});
