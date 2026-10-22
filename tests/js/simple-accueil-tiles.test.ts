import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

// Maquette 01 : l'accueil mobile expose quatre tuiles d'action alignées en
// grille 2 × 2 — Vendre (vert), Caisse (orange), Stock (bleu), Clients
// (violet). Le code n'en déclarait que deux (Vendre, Caisse).

void test('Accueil declare les quatre tuiles de la maquette 01', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');

    assert.match(src, /libelle:\s*'Vendre'/, 'tuile Vendre attendue');
    assert.match(src, /libelle:\s*'Caisse'/, 'tuile Caisse attendue');
    assert.match(src, /libelle:\s*'Stock'/, 'tuile Stock attendue');
    assert.match(src, /libelle:\s*'Clients'/, 'tuile Clients attendue');

    // les couleurs correspondent à la maquette
    assert.match(src, /couleur:\s*'vert'/);
    assert.match(src, /couleur:\s*'orange'/);
    assert.match(src, /couleur:\s*'bleu'/);
    assert.match(src, /couleur:\s*'violet'/);
});

void test('Accueil importe les routes Stock et Clients du namespace simple', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    // l'écran Accueil doit utiliser les routes générées pour /simple/stock
    // et /simple/clients, comme il utilise déjà /simple/vendre et /simple/caisse
    assert.match(
        src,
        /import\s+\{[^}]*\bsimpleStock\b[^}]*\}\s+from\s+['"]@\/routes\/simple['"]/s,
        'route Stock manquante',
    );
    assert.match(
        src,
        /import\s+\{[^}]*\bsimpleCustomers?\b[^}]*\}\s+from\s+['"]@\/routes\/simple['"]/s,
        'route Clients manquante',
    );
});

void test('Accueil accepte Stock et Clients dans le filtre tiles', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    // le type Tile doit couvrir les quatre valeurs pour que le filtre
    // backend puisse les passer
    assert.match(
        src,
        /type\s+Tile\s*=\s*['"]sell['"]\s*\|\s*['"]cash['"]\s*\|\s*['"]stock['"]\s*\|\s*['"]customers?['"]/,
    );
});
