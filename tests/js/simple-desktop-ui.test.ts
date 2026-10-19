import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const layout = readFileSync(
    new URL('../../resources/js/layouts/SimpleLayout.vue', import.meta.url),
    'utf8',
);
const accueil = readFileSync(
    new URL('../../resources/js/pages/simple/Accueil.vue', import.meta.url),
    'utf8',
);
const vendre = readFileSync(
    new URL('../../resources/js/pages/simple/Vendre.vue', import.meta.url),
    'utf8',
);
const caisse = readFileSync(
    new URL('../../resources/js/pages/simple/Caisse.vue', import.meta.url),
    'utf8',
);
const ventes = readFileSync(
    new URL('../../resources/js/pages/simple/Ventes.vue', import.meta.url),
    'utf8',
);
const stock = readFileSync(
    new URL('../../resources/js/pages/simple/Stock.vue', import.meta.url),
    'utf8',
);

void test('le mode simple dispose d’un shell ordinateur à menu latéral', () => {
    assert.match(layout, /lg:grid-cols-\[248px_minmax\(0,1fr\)\]/);
    assert.match(layout, /lg:flex/);
    assert.match(layout, /aria-label="Navigation principale"/);
    assert.match(layout, /Caisse/);
});

void test('Vendre garde son catalogue et son panier côte à côte sur ordinateur', () => {
    assert.ok(vendre.includes('lg:grid-cols-[minmax(0,1fr)_400px]'));
    assert.match(vendre, /lg:sticky lg:top-6/);
});

void test('Mes ventes devient une liste avec détail latéral sur ordinateur', () => {
    assert.ok(ventes.includes('lg:grid-cols-[minmax(0,1fr)_360px]'));
    assert.match(ventes, /Détail de la vente/);
});

void test('la navigation ordinateur présente seulement les modules disponibles', () => {
    const debut = layout.indexOf('const navigationOrdinateur');
    const fin = layout.indexOf('</script>');
    const navigationOrdinateur = layout.slice(debut, fin);

    assert.doesNotMatch(navigationOrdinateur, /libelle: 'Produits'/);
    assert.match(navigationOrdinateur, /libelle: 'Stock'/);
    assert.doesNotMatch(navigationOrdinateur, /libelle: 'Clients'/);
    assert.match(layout, /aria-current="entree\.actif \? 'page' : undefined"/);
});

void test('chaque écran simple conserve un titre principal sur ordinateur', () => {
    for (const page of [accueil, vendre, caisse, ventes, stock]) {
        assert.match(page, /<h1[^>]*lg:/);
    }
});

void test('Stock laisse le shell simple commun gérer la navigation', () => {
    assert.doesNotMatch(stock, /import SimpleLayout/);
    assert.doesNotMatch(stock, /<SimpleLayout/);
    assert.match(stock, /sr-only[^"']*lg:not-sr-only/);
});
