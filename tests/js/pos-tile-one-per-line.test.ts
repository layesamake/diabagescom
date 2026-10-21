import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Disposition demandée : un élément par ligne dans la tuile d'article.
// Photo, puis nom, puis prix, puis stock — jamais deux sur la même ligne.
void test('chaque élément de la tuile article occupe sa propre ligne', () => {
    const page = readFileSync('resources/js/pages/sales/Pos.vue', 'utf8');
    const debut = page.indexOf('v-for="product in frequent"');
    const fin = page.indexOf('</button>', debut);
    assert.ok(debut !== -1 && fin !== -1, 'tuile d’article introuvable');
    const tuile = page.slice(debut, fin);

    // L'en-tête ne doit plus regrouper photo et nom sur une même ligne.
    assert.doesNotMatch(
        tuile,
        /flex items-start gap-3/,
        'photo et nom ne doivent pas partager une ligne',
    );
    // Le pied ne doit plus regrouper prix et stock sur une même ligne.
    assert.doesNotMatch(
        tuile,
        /justify-between/,
        'prix et stock ne doivent pas partager une ligne',
    );
    // Les quatre éléments restent présents, dans l'ordre de lecture.
    const ordre = [
        'product.image_url',
        'product.name',
        'formatXof',
        'tile_stock',
    ].map((jeton) => tuile.indexOf(jeton));
    for (const position of ordre) {
        assert.ok(position !== -1, `élément absent de la tuile : ${position}`);
    }
    assert.deepEqual(
        [...ordre].sort((a, b) => a - b),
        ordre,
        'ordre attendu : photo, nom, prix, stock',
    );
});
