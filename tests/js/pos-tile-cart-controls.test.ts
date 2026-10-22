import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

// Maquette 02b : un article déjà dans le panier doit afficher un badge de
// quantité en haut à droite et des boutons − / + en bas de la tuile (à la
// place du simple clic qui l'ajoute). Un article pas encore dans le panier
// garde le comportement « un clic ajoute ».

void test('les tuiles affichent un badge quantite quand l article est dans le panier', () => {
    const src = readFileSync('resources/js/pages/sales/Pos.vue', 'utf8');
    assert.match(src, /quantiteDansPanier|quantityOf|qteDe|qteDansPanier/);
    assert.match(src, /--s-vert/);
    assert.match(
        src,
        /absolute\s+(?:top-\d+\s+right-\d+|right-\d+\s+top-\d+)/,
        'badge en haut à droite (classes absolute + top-* + right-*)',
    );
});

void test('les tuiles dans le panier exposent des boutons + et - au lieu d un clic unique', () => {
    const src = readFileSync('resources/js/pages/sales/Pos.vue', 'utf8');
    const debut = src.indexOf('v-for="product in frequent"');
    const fin = src.indexOf('</section>', debut);
    const tuile = src.slice(debut, fin !== -1 ? fin : debut + 4000);
    assert.match(
        tuile,
        /tile_increment/,
        'clé i18n tile_increment attendue (bouton +)',
    );
    assert.match(
        tuile,
        /tile_decrement/,
        'clé i18n tile_decrement attendue (bouton -)',
    );
    assert.match(tuile, /<Plus\b/, 'icône Plus attendue');
    assert.match(tuile, /<Minus\b/, 'icône Minus attendue');
});

void test('les boutons + et - appellent step(line, delta) sans toucher addProduct', () => {
    const src = readFileSync('resources/js/pages/sales/Pos.vue', 'utf8');
    const debut = src.indexOf('v-for="product in frequent"');
    const fin = src.indexOf('</section>', debut);
    const tuile = src.slice(debut, fin !== -1 ? fin : debut + 4000);
    assert.match(
        tuile,
        /step\([\s\S]*?CartLine,\s*1[,\s]*\)/,
        'appel step(..., 1) attendu',
    );
    assert.match(
        tuile,
        /step\([\s\S]*?CartLine,\s*-1[,\s]*\)/,
        'appel step(..., -1) attendu',
    );
});
