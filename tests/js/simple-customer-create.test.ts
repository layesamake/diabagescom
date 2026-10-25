import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

/*
 * « Nouveau client » du mode simple (maquette 08a) : bouton violet en bas de la
 * liste, formulaire à deux champs, clients sans dette visibles.
 */
const liste = readFileSync('resources/js/pages/simple/Clients.vue', 'utf8');
const formulaire = readFileSync(
    'resources/js/pages/simple/ClientNouveau.vue',
    'utf8',
);

void test('la liste montre les clients qui ne doivent rien, avec un appel direct', () => {
    assert.match(liste, /settled: ClientSansDette\[\]/);
    assert.match(liste, /ne doit rien/);
    assert.match(liste, /:href="`tel:\$\{client\.phone\}`"/);
    assert.match(liste, /<Avatar :nom="client\.name"/);
});

void test('le bouton « Nouveau client » est violet et réservé à qui peut créer', () => {
    assert.match(liste, /v-if="can\.create"/);
    assert.match(liste, /href="\/simple\/clients\/nouveau"/);
    assert.match(liste, /var\(--s-violet\)/);
    assert.match(liste, /Nouveau client/);
});

void test('le formulaire demande un nom et un téléphone facultatif, et poste vers /simple/clients', () => {
    assert.match(formulaire, /useForm\(\{ name: '', phone: '' \}\)/);
    assert.match(formulaire, /form\.post\('\/simple\/clients'/);
    assert.match(formulaire, /type="tel"/);
    assert.match(formulaire, /Téléphone \(facultatif\)/);
    // Le serveur exige au moins 2 caractères : le bouton attend la même chose.
    assert.match(formulaire, /form\.name\.trim\(\)\.length >= 2/);
});

void test('les erreurs du serveur sont annoncées et rattachées à leur champ', () => {
    assert.match(formulaire, /role="alert"/);
    assert.match(formulaire, /aria-describedby/);
    assert.match(formulaire, /form\.errors\.name/);
    assert.match(formulaire, /form\.errors\.phone/);
});

void test('la page « Nouveau client » a son texte d’aide', () => {
    const dictionnaire = readFileSync('resources/js/i18n/fr.ts', 'utf8');

    assert.match(dictionnaire, /'simple\/ClientNouveau':/);
});
