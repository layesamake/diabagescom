import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const layout = readFileSync(
    new URL('../../resources/js/layouts/SimpleLayout.vue', import.meta.url),
    'utf8',
);

void test('le shell simple affiche un squelette pendant les navigations Inertia', () => {
    assert.match(
        layout,
        /import\s*\{[^}]*router[^}]*\}\s*from\s*'@inertiajs\/vue3'/,
        'le shell doit écouter le cycle de navigation Inertia',
    );
    assert.match(layout, /router\.on\('start'/);
    assert.match(layout, /router\.on\('finish'/);
    assert.match(
        layout,
        /onBeforeUnmount/,
        'les écouteurs Inertia doivent être retirés',
    );
    assert.match(
        layout,
        /:inert="enChargement\s*\?\s*''\s*:\s*undefined"/,
        'la navigation et le contenu masqués doivent devenir inertes',
    );
    assert.match(
        layout,
        /<ChargementEcran\b[\s\S]{0,200}?class="fixed inset-0 z-\[60\](?:\s|")/,
        'le squelette doit couvrir le viewport au-dessus de la navigation et des modales',
    );
    assert.match(
        layout,
        /aria-busy="enChargement"/,
        'le contenu doit annoncer son état occupé aux lecteurs d’écran',
    );
});

void test('le squelette de chargement respecte la réduction des mouvements', () => {
    const chargement = readFileSync(
        new URL(
            '../../resources/js/components/simple/ChargementEcran.vue',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(chargement, /motion-safe:animate-spin/);
    assert.match(chargement, /motion-safe:animate-pulse/);
});
