import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const layout = readFileSync(
    new URL('../../resources/js/layouts/SimpleLayout.vue', import.meta.url),
    'utf8',
);
const vendre = readFileSync(
    new URL('../../resources/js/pages/simple/Vendre.vue', import.meta.url),
    'utf8',
);
const ventes = readFileSync(
    new URL('../../resources/js/pages/simple/Ventes.vue', import.meta.url),
    'utf8',
);

test('le mode simple dispose d’un shell ordinateur à menu latéral', () => {
    assert.match(layout, /lg:grid-cols-\[248px_minmax\(0,1fr\)\]/);
    assert.match(layout, /lg:flex/);
    assert.match(layout, /aria-label="Navigation principale"/);
    assert.match(layout, /Caisse/);
});

test('Vendre garde son catalogue et son panier côte à côte sur ordinateur', () => {
    assert.ok(vendre.includes('lg:grid-cols-[minmax(0,1fr)_400px]'));
    assert.match(vendre, /lg:sticky lg:top-6/);
});

test('Mes ventes devient une liste avec détail latéral sur ordinateur', () => {
    assert.ok(ventes.includes('lg:grid-cols-[minmax(0,1fr)_360px]'));
    assert.match(ventes, /Détail de la vente/);
});
