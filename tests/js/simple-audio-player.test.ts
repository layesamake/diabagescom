import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import {
    LecteurAudio,
    type ElementAudio,
} from '../../resources/js/lib/audioPlayer.ts';

/*
 * Haut-parleurs du mode simple : les morceaux d'une phrase se jouent à la
 * suite, un nouvel appui coupe la lecture en cours, et aucune icône ne
 * s'affiche pour une phrase non enregistrée.
 */
type Faux = ElementAudio & { url: string; fini: () => void; coupe: boolean };

function usine(journal: Faux[], echec?: string) {
    return (url: string): ElementAudio => {
        const element: Faux = {
            url,
            coupe: false,
            onended: null,
            onerror: null,
            fini: () => element.onended?.(),
            play: () =>
                url === echec
                    ? Promise.reject(new Error('refus'))
                    : Promise.resolve(),
            pause: () => {
                element.coupe = true;
            },
        };
        journal.push(element);

        return element;
    };
}

const tick = () => new Promise((resoudre) => setImmediate(resoudre));

void test('les morceaux sont joués dans l’ordre, un par un', async () => {
    const journal: Faux[] = [];
    const lecteur = new LecteurAudio(usine(journal));

    const fin = lecteur.jouer(['/a', '/b', '/c']);
    await tick();
    assert.deepEqual(
        journal.map((e) => e.url),
        ['/a'],
    );

    journal[0].fini();
    await tick();
    assert.deepEqual(
        journal.map((e) => e.url),
        ['/a', '/b'],
    );

    journal[1].fini();
    await tick();
    journal[2].fini();

    assert.equal(await fin, true);
});

void test('un nouvel appui coupe la lecture en cours', async () => {
    const journal: Faux[] = [];
    const lecteur = new LecteurAudio(usine(journal));

    const premiere = lecteur.jouer(['/a', '/b']);
    await tick();
    const seconde = lecteur.jouer(['/x']);
    await tick();

    assert.equal(journal[0].coupe, true);
    assert.equal(await premiere, false);
    // Le second morceau de la première phrase n'est jamais joué.
    assert.deepEqual(
        journal.map((e) => e.url),
        ['/a', '/x'],
    );

    journal[1].fini();
    assert.equal(await seconde, true);
});

void test('un morceau illisible arrête la phrase sans planter', async () => {
    const journal: Faux[] = [];
    const lecteur = new LecteurAudio(usine(journal, '/b'));

    const fin = lecteur.jouer(['/a', '/b', '/c']);
    await tick();
    journal[0].fini();

    assert.equal(await fin, false);
    assert.deepEqual(
        journal.map((e) => e.url),
        ['/a', '/b'],
    );
});

void test('arreter() coupe sans rien jouer de plus', async () => {
    const journal: Faux[] = [];
    const lecteur = new LecteurAudio(usine(journal));

    const fin = lecteur.jouer(['/a', '/b']);
    await tick();
    lecteur.arreter();

    assert.equal(await fin, false);
    assert.equal(journal.length, 1);
    assert.equal(journal[0].coupe, true);
});

void test('aucun écran du mode simple ne force le haut-parleur à « disponible »', () => {
    const dossier = 'resources/js/pages/simple';
    const fautes = readdirSync(dossier)
        // La page de démonstration des composants montre volontairement les deux états.
        .filter((nom) => nom.endsWith('.vue') && nom !== 'Demo.vue')
        .filter((nom) =>
            /:audio-disponible="true"|audio-disponible=true/.test(
                readFileSync(join(dossier, nom), 'utf8'),
            ),
        );

    assert.deepEqual(fautes, [], `haut-parleur forcé : ${fautes.join(', ')}`);
});

void test('les écrans qui affichent un haut-parleur le branchent sur le lecteur', () => {
    const dossier = 'resources/js/pages/simple';
    const avecHautParleur = readdirSync(dossier)
        .filter((nom) => nom.endsWith('.vue') && nom !== 'Demo.vue')
        .filter((nom) =>
            readFileSync(join(dossier, nom), 'utf8').includes(':code-audio='),
        );

    assert.ok(avecHautParleur.length >= 4);

    for (const nom of avecHautParleur) {
        const src = readFileSync(join(dossier, nom), 'utf8');

        assert.match(src, /useAudio\(\)/, `${nom} doit utiliser useAudio`);
        assert.match(src, /audio\.disponible\(audio_code\)/, nom);
        assert.match(src, /@jouer=/, `${nom} doit écouter « jouer »`);
    }
});
