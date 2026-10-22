import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Maquette 01 (mobile, 360 px) - accueil : Bonjour/Bonsoir, date, badge
// Essai, alerte "a recuperer" (credit), section "Cette semaine".

void test('Accueil affiche Bonjour ou Bonsoir et le prenom', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(src, /(Bonsoir|Bonjour)\b/);
    assert.match(src, /prenom|preenom|user\.first_name|firstname/i);
});

void test('Accueil affiche la date du jour', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(
        src,
        /toLocaleDateString|Intl\.DateTimeFormat|formatDate|dateCourante/,
    );
});

void test('Accueil expose un badge Essai', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(src, /Essai/);
});

void test('Accueil parle de credit et plus de caisse a fermer', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(src, /a r[eé]cup[eé]rer/i);
    assert.doesNotMatch(src, /caisse a fermer/);
});

void test('Accueil presente une section Cette semaine', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(src, /Cette semaine/);
});

void test('Accueil recoit les donnees 7 jours du controleur', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(
        src,
        /week\s*\??:\s*\{[^}]*jour[^}]*\}|\bweek\s*\??:\s*Jour\[\]/,
    );
});
