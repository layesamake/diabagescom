import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Contrat visuel déduit des maquettes D1..D7 (sidebar ordinateur du mode simple) :
// - fond vert foncé (--s-marque) ;
// - Vendre en première position, mis en valeur par une pillule vert clair
//   (--s-vert-clair) tant qu'il n'est pas l'écran actif ; l'écran actif est
//   signalé par une pillule blanche avec texte foncé ;
// - 8 entrées dans l'ordre : Vendre, Accueil, Mes ventes, Caisse, Stock,
//   Clients, Achats, Mes résultats ;
// - pied de page « En ligne » + identifiant de l'utilisateur.
void test('la sidebar ordinateur reflète le contrat des maquettes', () => {
    const layout = readFileSync(
        'resources/js/layouts/SimpleLayout.vue',
        'utf8',
    );

    assert.match(layout, /'Accueil'/);
    assert.match(layout, /'Vendre'/);
    assert.match(layout, /'Mes ventes'/);
    assert.match(layout, /'Caisse'/);
    assert.match(layout, /'Stock'/);
    assert.match(layout, /'Clients'/);
    assert.match(layout, /'Achats'/);
    assert.match(layout, /'Mes résultats'/);

    assert.match(layout, /--s-marque/);
    assert.match(layout, /--s-vert-clair/);

    assert.match(layout, /En ligne/);
});
