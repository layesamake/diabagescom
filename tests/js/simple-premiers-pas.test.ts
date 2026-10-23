import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

// L'écran Premiers pas doit reproduire la maquette 03 : pastille d'étape
// « 2 / 11 », points de progression, étapes terminées avec coche verte,
// étape courante mise en valeur par un cadre orange, étapes à venir en gris,
// pied de page « + N autres étapes ».

const page = 'resources/js/pages/simple/PremiersPas.vue';

void test('la page Premiers pas existe', () => {
    assert.ok(existsSync(page), `${page} introuvable`);
});

void test('Premiers pas utilise les compteurs reels du guide', () => {
    const src = readFileSync(page, 'utf8');
    assert.match(src, /useGuide/, 'progression serveur attendue');
    assert.match(src, /<EnTeteEcran\b/, 'EnTeteEcran utilisé');
    assert.match(src, /titre="Premiers pas"/, 'titre attendu');
    assert.match(
        src,
        /:etape="positionCourante"/,
        'position courante attendue',
    );
    assert.match(src, /:etape-total="total"/, 'total réel attendu');
});

void test('la barre de progression suit les etapes reelles', () => {
    const src = readFileSync(page, 'utf8');
    const region = src.match(/<BarreEtapes\b[\s\S]*?\/>/);
    assert.ok(region, 'BarreEtapes attendue');
    assert.match(region[0], /:total="total"/);
    assert.match(region[0], /:faites="faites"/);
    assert.match(region[0], /:en-cours="enCours !== null"/);
});

void test('Premiers pas affiche les etapes reelles et peut les deplier', () => {
    const src = readFileSync(page, 'utf8');
    assert.match(src, /v-for="etape in visibles"/, 'liste des étapes attendue');
    assert.match(src, /afficherToutes/, 'dépliage attendu');
    assert.match(src, /autres étapes/);
    assert.match(
        src,
        /stepText\(etape\.key, 'title'\)/,
        'libellé métier attendu',
    );
});

void test('Premiers pas met en avant et ouvre la prochaine action metier', () => {
    const src = readFileSync(page, 'utf8');
    assert.match(src, /const enCours = computed/, 'prochaine étape attendue');
    assert.match(src, /--s-orange/, 'cadre orange attendu');
    assert.match(src, /<Link\b/, 'navigation Inertia attendue');
    assert.match(
        src,
        /:href="enCours\.href"/,
        'lien de l’action réelle attendu',
    );
    assert.match(src, /Commencer/, 'bouton Commencer attendu');
});

void test('la route simple du guide est branchee sur son controleur', () => {
    const routes = readFileSync('routes/web.php', 'utf8');
    assert.match(
        routes,
        /Route::get\('simple\/guide', \[SimpleGuideController::class, 'index'\]\)/,
    );
});
