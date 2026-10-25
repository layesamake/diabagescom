import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/simple/Clients.vue', import.meta.url),
    'utf8',
);

void test('Clients affiche un appel direct pour chaque numéro connu', () => {
    assert.match(page, /:href="`tel:\$\{customer\.phone\}`"/);
});
