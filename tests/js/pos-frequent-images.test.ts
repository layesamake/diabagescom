import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

void test('les articles fréquents de la vente comptoir affichent une vignette', () => {
    const page = readFileSync('resources/js/pages/sales/Pos.vue', 'utf8');

    assert.match(page, /product\.image_url/);
    assert.match(page, /<img/);
});
