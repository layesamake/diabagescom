import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Contrat visuel de la barre basse (maquette 01-accueil) : 5 entrées sur toute
// la largeur, Vendre en pastille verte surélevée, entrée active en vert,
// « Plus » représentée par une icône de menu, pas des points de suspension.
void test('la barre basse mobile reflète le contrat de la maquette accueil', () => {
    const barre = readFileSync(
        'resources/js/components/simple/BarreNavBas.vue',
        'utf8',
    );

    assert.doesNotMatch(
        barre,
        /max-w-\[\d+px\]/,
        'la barre doit occuper toute la largeur, pas une largeur bornée',
    );
    assert.match(barre, /entree\.cle === 'vendre'/);
    assert.match(barre, /rounded-full/);
    assert.match(barre, /-mt-\d+/);
    assert.match(barre, /--s-vert/);
    assert.match(
        barre,
        /Menu/,
        'l’entrée Plus doit utiliser une icône de menu',
    );
    assert.doesNotMatch(barre, /MoreHorizontal/);
});
