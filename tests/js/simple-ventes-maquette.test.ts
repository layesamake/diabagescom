import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = readFileSync(
    new URL('../../resources/js/pages/simple/Ventes.vue', import.meta.url),
    'utf8',
);

void test("Ventes expose un sélecteur de jour en pilule avec Aujourd'hui", () => {
    assert.match(source, /Calendrier|LucideCalendar|ChevronLeft|ChevronRight/);
    assert.match(source, /Aujourd['’']hui/);
    assert.doesNotMatch(source, /Hier\s*</);
    assert.doesNotMatch(source, />\s*Demain/);
});

void test('Ventes affiche le total du jour et le nombre de ventes', () => {
    assert.match(source, /total/);
    assert.match(source, /count/);
    assert.match(source, /12\s*ventes|ventes/);
});

void test('Ventes expose trois cartes par mode avec icônes (espèces, téléphone, crédit)', () => {
    assert.match(source, /Banknote/);
    assert.match(source, /Smartphone/);
    assert.match(source, /Clock/);
    assert.match(source, /by_method\.cash/);
    assert.match(source, /by_method\.mobile/);
    assert.match(source, /by_method\.credit/);
});

void test("Ventes affiche chaque vente avec ses badges d'articles et son mode", () => {
    assert.match(source, /vente\.products/);
    assert.match(source, /vente\.method/);
    assert.match(source, /vente\.time/);
    assert.match(source, /initials/);
});

void test("Ventes limite l'affichage à deux badges d'articles par ligne", () => {
    assert.match(source, /slice\(0,\s*2\)/);
});
