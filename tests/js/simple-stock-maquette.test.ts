import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const stock = readFileSync(
    new URL('../../resources/js/pages/simple/Stock.vue', import.meta.url),
    'utf8',
);
// Le formateur coupe les textes longs : on aplatit pour que les assertions
// portent sur le contenu rendu et non sur la mise en forme du fichier.
const source = stock.replace(/\s+/g, ' ').replace(/’/g, "'");

void test('Stock utilise EnTeteEcran et lui passe le code audio « Vous êtes sur Stock »', () => {
    assert.match(stock, /EnTeteEcran/);
    assert.match(source, /code-audio="audio_code"/);
    assert.match(source, /audio-disponible="true"/);
});

void test('Stock affiche trois cartes compteur toujours en fond plein coloré', () => {
    // Les compteurs sont affichés en tuiles colorées solides (07a) : on n'utilise
    // plus le filtre toggle de CompteursFiltres pour cet écran.
    assert.doesNotMatch(source, /CompteursFiltres/);
    assert.match(source, /Assez/);
    assert.match(source, /Bientôt fini/);
    assert.match(source, /Presque fini/);
    assert.match(source, /fondPlein/);
    assert.match(source, /fondClair/);
});

void test('Stock affiche une ligne par article avec photo, nom, barre, quantité et boutons +/−', () => {
    assert.match(source, /progress/);
    assert.match(source, /role="progressbar"/);
    assert.match(source, /Ajouter au stock/);
    assert.match(source, /Retirer du stock/);
    // Couleurs de la barre et des boutons via la palette partagée
    assert.match(source, /fondClair\.vert/);
    assert.match(source, /fondClair\.rouge/);
});

void test('Stock ouvre une feuille « Pourquoi ? » pour motif de retrait (07b)', () => {
    assert.match(source, /Pourquoi/);
    assert.match(source, /abîmé/);
    assert.match(source, /mort/);
    assert.match(source, /perdu/);
    // La maquette affiche les motifs avec une capitale : le code capitalise.
    assert.match(source, /capitaliser\(motif\)/);
    assert.match(source, /Retour/);
});
