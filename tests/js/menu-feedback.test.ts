import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const sidebar = readFileSync(
    new URL('../../resources/js/components/AppSidebar.vue', import.meta.url),
    'utf8',
);
const navigation = readFileSync(
    new URL('../../resources/js/components/NavMain.vue', import.meta.url),
    'utf8',
);

void test('le menu propose un lien externe idées et signalements', () => {
    assert.match(sidebar, /Idées & signalements/);
    assert.match(sidebar, /https:\/\/forms\.gle\/U7MjCuVzzAiTsXQX6/);
    assert.match(
        sidebar,
        /Signalez un problème ou partagez une idée pour améliorer Diaba Cloud\./,
    );
    assert.match(navigation, /target="_blank"/);
});
