import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

/*
 * Stock (mode simple) : la vraie photo de l'article, sinon ses initiales sur la
 * couleur du statut — plus le mot « photo » (règle 6 du cahier des charges).
 */
const stock = readFileSync(
    'resources/js/pages/simple/Stock.vue',
    'utf8',
).replace(/\s+/g, ' ');
const controleur = readFileSync(
    'app/Http/Controllers/Simple/SimpleStockController.php',
    'utf8',
);

void test('le serveur envoie l’adresse signée de la photo de chaque article', () => {
    assert.match(controleur, /'products\.image_path', 'products\.version'/);
    assert.match(
        controleur,
        /'image_url' => CatalogImages::url\('products\.image'/,
    );
});

void test('la page montre la vraie photo quand elle existe', () => {
    assert.match(stock, /image_url: string \| null/);
    assert.match(stock, /<Avatar v-if="produit\.image_url"/);
    assert.match(stock, /:photo-url="produit\.image_url"/);
    assert.match(stock, /forme="carre"/);
});

void test('sans photo, la page montre les initiales sur la couleur du statut, pas le mot « photo »', () => {
    assert.match(stock, /produit\.name\.slice\(0, 2\)\.toUpperCase\(\)/);
    assert.match(stock, /fondClair\[COULEURS\[produit\.status\]\]/);
    assert.doesNotMatch(stock, />\s*photo\s*</);
});
