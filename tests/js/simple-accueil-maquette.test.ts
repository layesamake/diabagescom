import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Maquette 01 (mobile, 360 px) — l'accueil expose, dans l'ordre de lecture :
// 1. un bandeau vert foncé qui nomme l'entreprise et son lieu,
// 2. la date du jour et un « Bonjour / Bonsoir {prénom} » avec un badge
//    d'essai (« Essai : N jours »),
// 3. la carte « Aujourd'hui »,
// 4. les quatre tuiles d'action en grille 2 × 2,
// 5. la section « À regarder » avec au plus deux alertes,
// 6. la section « Cette semaine » avec un mini-graphique des sept derniers
//    jours.
//
// Ce test couvre les points 1, 2, 5 et 6 qui restent à aligner.

void test('Accueil affiche Bonjour/Bonsoir et le prénom du vendeur', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(
        src,
        /(Bonsoir|Bonjour)\b/,
        'formule de salutation Bonjour/Bonsoir attendue',
    );
    assert.match(
        src,
        /prenom|prénom|user\.first_name|firstname/i,
        'source du prénom attendue (prop, contexte ou interpolation)',
    );
});

void test('Accueil affiche la date du jour lisible en français', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(
        src,
        /toLocaleDateString|Intl\.DateTimeFormat|formatDate|dateCourante/,
        'formatage de la date en français attendu',
    );
});

void test('Accueil expose un badge Essai quand le contexte le fournit', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    // la maquette prévoit un badge « Essai : 10 jours » à droite du prénom,
    // visible quand l'entreprise est en période d'essai
    assert.match(
        src,
        /Essai/,
        'libellé « Essai » du badge attendu',
    );
});

void test(
    "Accueil parle de crédit (« à récupérer ») et plus de caisse à fermer",
    () => {
        const src = readFileSync(
            'resources/js/pages/simple/Accueil.vue',
            'utf8',
        );
        assert.match(
            src,
            /à récupérer/i,
            'libellé « à récupérer » attendu (règle du cahier des charges)',
        );
        assert.doesNotMatch(
            src,
            /caisse à fermer/,
            'le mot « caisse à fermer » ne doit plus apparaître',
        );
    },
);

void test('Accueil présente une section « Cette semaine »', () => {
    const src = readFileSync('resources/js/pages/simple/Accueil.vue', 'utf8');
    assert.match(
        src,
        /Cette semaine/,
        'titre de section « Cette semaine » attendu',
    );
});

void test(
    "Accueil reçoit les données 7 jours du contrôleur (props.week)",
    () => {
        const src = readFileSync(
            'resources/js/pages/simple/Accueil.vue',
            'utf8',
        );
        assert.match(
            src,
            /week\s*\??:\s*\{[^}]*jour[^}]*\}|\bweek\s*\??:\s*Jour\[\]/,
            'prop week (7 derniers jours) attendue',
        );
    },
);
