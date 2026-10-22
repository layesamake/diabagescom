import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Maquette 02 (mobile) : alignement de l'écran Vendre.
// Couvre 02a (panier vide) et 02b (panier rempli). La maquette ajoute
// trois éléments par rapport au lot 2 livré :
// 1. une barre de recherche en tête de catalogue (filtre local des
//    articles par nom) ;
// 2. un bandeau d'aide « Touchez un article pour l'ajouter » quand le
//    panier est vide ;
// 3. un raccourci vers le paiement quand le panier est non vide (le
//    « Total · N articles » ancré en bas est déjà géré par le composant
//    panier, on vérifie juste la présence du pavé paiement).

void test('Vendre expose une barre de recherche au-dessus du catalogue', () => {
    const src = readFileSync('resources/js/pages/simple/Vendre.vue', 'utf8');
    assert.match(
        src,
        /<input[^>]*type=["']search["']|<input[^>]*v-model=["']recherche["']/,
        'input recherche attendu',
    );
    assert.match(src, /recherche/, 'état réactif de la recherche attendu');
});

void test('Vendre affiche un bandeau quand le panier est vide', () => {
    const src = readFileSync('resources/js/pages/simple/Vendre.vue', 'utf8');
    assert.match(
        src,
        /Touchez un article/,
        "message d'aide du bandeau attendu",
    );
    assert.match(
        src,
        /panier\.length\s*===\s*0|panier\.length\s*<\s*1|v-if=["']panier\.length\s*===\s*0["']/,
        "condition d'affichage du bandeau attendue",
    );
});

void test('Vendre propose les methodes de paiement en bas quand le panier est rempli', () => {
    const src = readFileSync('resources/js/pages/simple/Vendre.vue', 'utf8');
    // La maquette 02b montre les méthodes paiement (Espèces, Wave,
    // Orange Money, Crédit) sous le panier quand celui-ci est non vide.
    assert.match(src, /Esp[èe]ces/, 'bouton Espèces attendu');
    assert.match(src, /Wave/, 'bouton Wave attendu');
    assert.match(src, /Orange Money/, 'bouton Orange Money attendu');
    assert.match(src, /Cr[ée]dit/, 'bouton Crédit attendu');
});
