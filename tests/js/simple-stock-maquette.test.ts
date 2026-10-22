import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const raw = readFileSync(
    new URL('../../resources/js/pages/simple/Stock.vue', import.meta.url),
    'utf8',
);
// Le formateur coupe les textes longs : on aplatit pour que les assertions
// portent sur le contenu rendu et non sur la mise en forme du fichier.
const source = raw.replace(/\s+/g, ' ').replace(/’/g, "'");

void test('Stock annonce le titre sur fond vert avec un haut-parleur', () => {
    assert.match(raw, /EnTeteEcran/);
    // L'écran Stock lit un message audio (icône haut-parleur dans l'en-tête).
    assert.match(source, /Volume2/);
    assert.match(source, /Speaker|BoutonHautParleur|codeAudio|haut-parleur/);
});

void test('Stock affiche trois cartes compteur toujours en fond plein coloré', () => {
    // Les compteurs sont affichés en tuiles colorées solides (07a) : on n'utilise
    // plus le filtre toggle de CompteursFiltres pour cet écran.
    assert.doesNotMatch(source, /CompteursFiltres/);
    assert.match(source, /Assez/);
    assert.match(source, /Bientôt fini/);
    assert.match(source, /Presque fini/);
    assert.match(source, /fondPlein/);
});

void test('Stock affiche une ligne par article avec photo, nom, barre, quantité et boutons +/−', () => {
    assert.match(source, /progress/);
    assert.match(source, /role="progressbar"|role="meter"/);
    assert.match(source, /Refaire|Ajouter|ajouter au stock|\+/);
    assert.match(source, /Retirer|retirer du stock|−|-/);
    // Couleurs de la photo placeholder selon le statut
    assert.match(source, /var\(--s-vert/);
    assert.match(source, /var\(--s-orange/);
    assert.match(source, /var\(--s-rouge/);
});

void test('Stock ouvre une feuille « Pourquoi ? » pour motif de retrait (07b)', () => {
    assert.match(source, /Pourquoi/);
    assert.match(source, /Abîmé/);
    assert.match(source, /Mort/);
    assert.match(source, /Perdu/);
    assert.match(source, /Retour/);
});