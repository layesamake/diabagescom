import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    cacheName,
    cleDe,
    lireManifest,
    lisible,
    MANIFEST_URL,
    purgerLesAutres,
    synchroniser,
    toutesLesAdresses,
    type AudioManifest,
    type CacheLike,
    type CachesLike,
} from '../../resources/js/lib/audioCache.ts';

/*
 * Messages audio gardés sur le téléphone : écoutables sans connexion, séparés
 * par compte et entreprise, mis à jour quand un morceau est réenregistré.
 */
class FauxCache implements CacheLike {
    contenu = new Map<string, string>();

    async match(cle: string): Promise<Response | undefined> {
        const corps = this.contenu.get(cleDe(cle));

        return corps === undefined ? undefined : new Response(corps);
    }

    async put(cle: string, reponse: Response): Promise<void> {
        this.contenu.set(cleDe(cle), await reponse.text());
    }

    async keys(): Promise<ReadonlyArray<{ url: string }>> {
        return [...this.contenu.keys()].map((cle) => ({
            url: `https://exemple.test${cle}`,
        }));
    }

    async delete(cle: string): Promise<boolean> {
        return this.contenu.delete(cleDe(cle));
    }
}

class FauxCaches implements CachesLike {
    caches = new Map<string, FauxCache>();

    async open(nom: string): Promise<CacheLike> {
        if (!this.caches.has(nom)) {
            this.caches.set(nom, new FauxCache());
        }

        return this.caches.get(nom) as FauxCache;
    }

    async keys(): Promise<string[]> {
        return [...this.caches.keys()];
    }

    async delete(nom: string): Promise<boolean> {
        return this.caches.delete(nom);
    }
}

const clip = (id: string, v = 1) => `/simple/audio/clips/${id}?v=${v}`;
const manifeste = (extra: Partial<AudioManifest> = {}): AudioManifest => ({
    version: 'v1',
    phrases: { 'STK-01': [clip('a')], 'ACH-01': [clip('b')] },
    digits: { 'CHF-01': clip('c') },
    ...extra,
});

/** Serveur factice : renvoie le manifeste et « télécharge » chaque morceau. */
function serveur(courant: () => AudioManifest, pannes: string[] = []) {
    const appels: string[] = [];
    const chercher = async (url: string): Promise<Response> => {
        appels.push(url);

        if (pannes.includes(url)) {
            throw new TypeError('réseau coupé');
        }

        return url === MANIFEST_URL
            ? new Response(JSON.stringify(courant()))
            : new Response(`audio:${url}`);
    };

    return { chercher, appels };
}

void test('le premier chargement télécharge le manifeste et chaque morceau, une fois', async () => {
    const caches = new FauxCaches();
    const { chercher, appels } = serveur(() => manifeste());

    const resultat = await synchroniser(caches, 'u1:t1', chercher);

    assert.deepEqual(resultat, manifeste());
    assert.equal(appels.filter((a) => a !== MANIFEST_URL).length, 3);
    const cache = caches.caches.get(cacheName('u1:t1')) as FauxCache;
    assert.equal(cache.contenu.size, 4); // 3 morceaux + le manifeste
    assert.deepEqual(await lireManifest(caches, 'u1:t1'), manifeste());
});

void test('une deuxième synchro ne retélécharge rien', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);

    const { chercher, appels } = serveur(() => manifeste());
    await synchroniser(caches, 'u1:t1', chercher);

    assert.deepEqual(appels, [MANIFEST_URL]);
});

void test('un morceau réenregistré (nouvelle date) est retéléchargé et l’ancien supprimé', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);

    const modifie = manifeste({
        phrases: { 'STK-01': [clip('a', 2)], 'ACH-01': [clip('b')] },
    });
    const { chercher, appels } = serveur(() => modifie);
    await synchroniser(caches, 'u1:t1', chercher);

    assert.deepEqual(appels, [MANIFEST_URL, clip('a', 2)]);
    const cache = caches.caches.get(cacheName('u1:t1')) as FauxCache;
    assert.equal(cache.contenu.has(clip('a')), false);
    assert.equal(cache.contenu.has(clip('a', 2)), true);
});

void test('une phrase retirée disparaît du cache, mais le manifeste reste', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);

    const reduit = manifeste({
        phrases: { 'STK-01': [clip('a')] },
        digits: {},
    });
    await synchroniser(caches, 'u1:t1', serveur(() => reduit).chercher);

    const cache = caches.caches.get(cacheName('u1:t1')) as FauxCache;
    assert.deepEqual(
        [...cache.contenu.keys()].sort(),
        [clip('a'), MANIFEST_URL].sort(),
    );
});

void test('un morceau qui n’arrive pas n’empêche pas les autres, et sera repris', async () => {
    const caches = new FauxCaches();
    const { chercher } = serveur(() => manifeste(), [clip('b')]);

    await synchroniser(caches, 'u1:t1', chercher);

    const cache = caches.caches.get(cacheName('u1:t1')) as FauxCache;
    assert.equal(cache.contenu.has(clip('a')), true);
    assert.equal(cache.contenu.has(clip('b')), false);

    // Retour du réseau : seul le morceau manquant est téléchargé.
    const suite = serveur(() => manifeste());
    await synchroniser(caches, 'u1:t1', suite.chercher);
    assert.deepEqual(suite.appels, [MANIFEST_URL, clip('b')]);
});

void test('sans réponse du serveur, le cache existant reste intact', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);

    const resultat = await synchroniser(
        caches,
        'u1:t1',
        async () => new Response('non', { status: 403 }),
    );

    assert.equal(resultat, null);
    assert.deepEqual(await lireManifest(caches, 'u1:t1'), manifeste());
});

void test('chaque compte et chaque entreprise a son cache : jamais de morceau d’un autre', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);
    assert.notEqual(await lireManifest(caches, 'u1:t1'), null);
    assert.equal(await lireManifest(caches, 'u2:t2'), null);

    // Un autre compte se connecte sur le même téléphone : l'ancien cache est supprimé.
    await synchroniser(
        caches,
        'u2:t2',
        serveur(() => manifeste({ phrases: {}, digits: {} })).chercher,
    );
    assert.equal(caches.caches.has(cacheName('u1:t1')), false);
    assert.equal(caches.caches.has(cacheName('u2:t2')), true);
});

void test('la déconnexion supprime tous les caches audio et laisse les autres caches', async () => {
    const caches = new FauxCaches();
    await caches.open(cacheName('u1:t1'));
    await caches.open(cacheName('u2:t2'));
    await caches.open('diaba-v5-assets');

    await purgerLesAutres(caches, null);

    assert.deepEqual([...caches.caches.keys()], ['diaba-v5-assets']);
});

void test('lisible : un morceau gardé se joue depuis le cache, un absent depuis le serveur', async () => {
    const caches = new FauxCaches();
    await synchroniser(caches, 'u1:t1', serveur(() => manifeste()).chercher);
    const liberes: string[] = [];
    const creer = () => 'blob:morceau-a';
    const liberer = (url: string) => liberes.push(url);

    const garde = await lisible(caches, 'u1:t1', clip('a'), creer, liberer);
    assert.equal(garde.url, 'blob:morceau-a');
    garde.liberer();
    assert.deepEqual(liberes, ['blob:morceau-a']);

    const absent = await lisible(caches, 'u1:t1', clip('zzz'), creer, liberer);
    assert.equal(absent.url, clip('zzz'));
    absent.liberer();
    assert.equal(liberes.length, 1);

    // Même morceau, autre compte : pas dans son cache, donc depuis le réseau.
    const autre = await lisible(caches, 'u2:t2', clip('a'), creer, liberer);
    assert.equal(autre.url, clip('a'));
});

void test('toutesLesAdresses ne compte chaque fichier qu’une fois, et cleDe ignore le domaine', () => {
    const m = manifeste({
        phrases: { A: [clip('a')], B: [clip('a'), clip('b')] },
        digits: { C: clip('b') },
    });

    assert.deepEqual(
        toutesLesAdresses(m).sort(),
        [clip('a'), clip('b')].sort(),
    );
    assert.equal(
        cleDe('https://diaba.cloud/simple/audio/clips/a?v=3'),
        '/simple/audio/clips/a?v=3',
    );
    assert.equal(
        cleDe('/simple/audio/clips/a?v=3'),
        '/simple/audio/clips/a?v=3',
    );
});

void test('le branchement : cadre simple, lecture depuis le cache d’abord, purge à la déconnexion', () => {
    const cadre = readFileSync('resources/js/layouts/SimpleLayout.vue', 'utf8');
    const audio = readFileSync('resources/js/composables/useAudio.ts', 'utf8');
    const sync = readFileSync('resources/js/offline/sync.ts', 'utf8');

    assert.match(cadre, /useAudioPrefetch\(\);/);
    // Une phrase sans variable est jouée depuis le cache avant tout appel réseau.
    assert.match(audio, /Object\.keys\(valeurs\)\.length === 0/);
    assert.ok(
        audio.indexOf('jouerDepuisLeCache(code)') <
            audio.indexOf('/simple/audio/${code}'),
    );
    assert.match(
        sync,
        /purgerLesAutres\(caches as unknown as CachesLike, null\)/,
    );
});
