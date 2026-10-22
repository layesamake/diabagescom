import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const raw = readFileSync(
    new URL('../../resources/js/pages/simple/Voix.vue', import.meta.url),
    'utf8',
);
const entete = readFileSync(
    new URL(
        '../../resources/js/components/simple/EnTeteEcran.vue',
        import.meta.url,
    ),
    'utf8',
);

// Le formateur (vp check) coupe les textes longs sur plusieurs lignes et
// uniformise les apostrophes : on aplatit les blancs et on normalise les
// apostrophes pour que les assertions portent sur le texte affiché et non sur
// la mise en forme du fichier.
const source = raw.replace(/\s+/g, ' ').replace(/’/g, "'");

void test('Voix annonce le rôle administrateur sous le titre', () => {
    assert.match(source, /sous-titre|sousTitre/);
    assert.match(source, /role/);
    assert.match(source, /acces_administrateur/);
    // Le cadenas est rendu par EnTeteEcran, alimenté par ces deux props.
    assert.match(entete, /Lock/);
    assert.match(entete, /sous-titre-verrouille|sousTitreVerrouille/);
});

void test('Voix affiche la langue choisie en lecture seule avec un bouton Changer', () => {
    // La maquette 05a montre « Wolof » en gros + un bouton « Changer ».
    assert.doesNotMatch(source, /<select/);
    assert.match(source, /Changer/);
    assert.match(source, /language_name/);
});

void test('Voix affiche le compteur « Enregistrés » avec le total du jour', () => {
    assert.match(source, /Enregistrés/);
    assert.match(source, /progress\.done/);
    assert.match(source, /progress\.total/);
    assert.match(source, /sur/);
});

void test('Voix affiche des onglets de groupes avec le nombre de phrases restantes', () => {
    assert.match(source, /restant|remaining/);
    assert.match(source, /groupe\.phrases\.length|total/);
    assert.match(source, /onglet|tab/i);
});

void test('Voix affiche le bandeau d’aide sur la façon de dicter', () => {
    assert.match(source, /à votre façon/);
    assert.match(source, /n'apparaît qu'à côté/);
});

void test('Voix propose un bouton plein rouge « Enregistrer » pour une phrase vide', () => {
    assert.match(source, /Enregistrer/);
    assert.match(source, /w-full/);
    assert.match(source, /s-rouge/);
});

void test('Voix affiche « J’écoute… parlez maintenant » et un bouton « Fini » pendant l’enregistrement', () => {
    assert.match(source, /J'écoute… parlez maintenant/);
    assert.match(source, /Fini/);
    assert.match(source, /Square/);
});

void test('Voix propose une feuille de choix de langue (maquette 05c) avec les 7 noms', () => {
    // Les noms viennent des props (voir SimpleVoiceMaquetteTest côté PHP) :
    // la feuille se contente de rendre chaque langue proposée.
    assert.match(source, /languages/);
    assert.match(source, /langue\.name/);
    assert.match(source, /Quelle langue pour la voix \?/);
    assert.match(source, /Une seule langue/);
    assert.match(source, /Fermer/);
});

void test('Voix propose un bouton vert « Terminer pour l’instant » en pied d’écran', () => {
    assert.match(source, /Terminer pour l'instant/);
    assert.match(source, /s-vert/);
});
