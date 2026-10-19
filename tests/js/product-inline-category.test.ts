import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const productForm = readFileSync(
    new URL(
        '../../resources/js/pages/catalog/ProductForm.vue',
        import.meta.url,
    ),
    'utf8',
);

void test('la fiche article permet de créer et sélectionner une catégorie depuis sa liste', () => {
    assert.match(productForm, /t\('catalog\.add_category'\)/);
    assert.match(productForm, /categoryRoutes\.save\.url\(\)/);
    assert.match(productForm, /router\.post\(/);
    assert.match(productForm, /\{ name \},/);
    assert.match(productForm, /categoryId\.value\s*=\s*category\.id/);
});
