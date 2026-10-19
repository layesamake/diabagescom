import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const stock = readFileSync(
    new URL('../../resources/js/pages/simple/Stock.vue', import.meta.url),
    'utf8',
);
const page = readFileSync(
    new URL(
        '../../resources/js/pages/simple/NouvelArticle.vue',
        import.meta.url,
    ),
    'utf8',
);

void test('le mode simple propose un formulaire court pour créer un article', () => {
    assert.match(page, /<h1[^>]*lg:/);
    assert.match(page, /v-model="form\.name"/);
    assert.match(page, /v-model\.number="form\.sale_price"/);
    assert.match(page, /v-model="form\.unit_id"/);
    assert.match(page, /v-model="form\.category_id"/);
    assert.match(page, /Photo de l'article/);
    assert.match(page, /form\.post\('\/simple\/articles'/);
});

void test('Stock donne accès à l’ajout seulement avec le droit catalogue', () => {
    assert.match(stock, /can\('catalog\.create'\)/);
    assert.match(stock, /href="\/simple\/articles\/nouveau"/);
});

void test('la création d’article simple garde le shell commun', () => {
    assert.doesNotMatch(page, /import SimpleLayout/);
    assert.doesNotMatch(page, /<SimpleLayout/);
});
