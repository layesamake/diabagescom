import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

// Cahier des charges du mode simple : aucun texte sous 15 px, aucune zone
// tactile sous 48 px (critères d'acceptation, contrôle automatique).
const dossiers = [
    'resources/js/components/simple',
    'resources/js/pages/simple',
];

// Le cadre commun (menu latéral, barre d'état) fait partie des écrans.
const fichiers = [
    ...dossiers.flatMap((dossier) =>
        readdirSync(dossier)
            .filter((nom) => nom.endsWith('.vue'))
            .map((nom) => join(dossier, nom)),
    ),
    'resources/js/layouts/SimpleLayout.vue',
];

void test('le mode simple compte des écrans à contrôler', () => {
    assert.ok(fichiers.length >= 20);
});

void test('aucun texte du mode simple n’est sous 15 px', () => {
    const fautes: string[] = [];

    for (const fichier of fichiers) {
        readFileSync(fichier, 'utf8')
            .split('\n')
            .forEach((ligne, index) => {
                if (
                    /\btext-(xs|sm)\b/.test(ligne) ||
                    (/\btext-\[(\d+(\.\d+)?)px\]/.test(ligne) &&
                        [
                            ...ligne.matchAll(/\btext-\[(\d+(?:\.\d+)?)px\]/g),
                        ].some((m) => Number(m[1]) < 15))
                ) {
                    fautes.push(`${fichier}:${index + 1}`);
                }
            });
    }

    assert.deepEqual(fautes, [], `texte sous 15 px :\n${fautes.join('\n')}`);
});

void test('aucun bouton du mode simple n’est sous 48 px', () => {
    const fautes: string[] = [];
    // size-N / h-N / min-h-N en unités Tailwind (N × 4 px) : 12 = 48 px.
    const petit = /\b(?:size|h|min-h|min-w|w)-(\d+)\b/g;

    for (const fichier of fichiers) {
        const src = readFileSync(fichier, 'utf8');

        for (const bouton of src.matchAll(/<button\b[^>]*>/gs)) {
            const balise = bouton[0];

            for (const m of balise.matchAll(petit)) {
                if (Number(m[1]) < 12) {
                    const ligne = src.slice(0, bouton.index).split('\n').length;
                    fautes.push(`${fichier}:${ligne} (${m[0]})`);
                }
            }
        }
    }

    assert.deepEqual(fautes, [], `bouton sous 48 px :\n${fautes.join('\n')}`);
});
