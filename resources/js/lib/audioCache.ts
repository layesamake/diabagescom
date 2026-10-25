/**
 * Cache des messages audio du mode simple, pour les écouter sans connexion
 * (cahier des charges : « tous les fichiers de l'entreprise, environ 5 Mo,
 * gardés en cache sur le téléphone après le premier chargement »).
 *
 * Un cache par compte et par entreprise : jamais de morceau d'une entreprise
 * restitué à une autre, et tout est supprimé à la déconnexion. Les adresses du
 * manifeste portent la date de prise : un morceau réenregistré est retéléchargé.
 */
export type AudioManifest = {
    version: string;
    /** Phrases sans variable : adresses des morceaux, dans l'ordre. */
    phrases: Record<string, string[]>;
    /** Mots de chiffres, pour dire des montants. */
    digits: Record<string, string>;
};

export interface CacheLike {
    match(key: string): Promise<Response | undefined>;
    put(key: string, response: Response): Promise<void>;
    keys(): Promise<ReadonlyArray<{ url: string }>>;
    delete(key: string): Promise<boolean>;
}

export interface CachesLike {
    open(name: string): Promise<CacheLike>;
    keys(): Promise<string[]>;
    delete(name: string): Promise<boolean>;
}

export const MANIFEST_URL = '/simple/audio/manifest';
const PREFIXE = 'diaba-audio-';

export function cacheName(identity: string): string {
    return `${PREFIXE}${identity}`;
}

/** Chemin + paramètres d'une adresse, qu'elle soit relative ou absolue. */
export function cleDe(url: string): string {
    const adresse = new URL(url, 'https://cache.invalid');

    return adresse.pathname + adresse.search;
}

export function toutesLesAdresses(manifest: AudioManifest): string[] {
    return [
        ...new Set([
            ...Object.values(manifest.phrases).flat(),
            ...Object.values(manifest.digits),
        ]),
    ];
}

/** Supprime les caches audio de tout autre compte ou entreprise (ou tous, si `garder` est nul). */
export async function purgerLesAutres(
    caches: CachesLike,
    garder: string | null,
): Promise<void> {
    const conserve = garder === null ? null : cacheName(garder);

    for (const nom of await caches.keys()) {
        if (nom.startsWith(PREFIXE) && nom !== conserve) {
            await caches.delete(nom);
        }
    }
}

export async function lireManifest(
    caches: CachesLike,
    identity: string,
): Promise<AudioManifest | null> {
    try {
        const cache = await caches.open(cacheName(identity));
        const trouve = await cache.match(MANIFEST_URL);

        return trouve ? ((await trouve.json()) as AudioManifest) : null;
    } catch {
        return null;
    }
}

/**
 * Met le cache à jour : télécharge les morceaux manquants, supprime ceux qui ne
 * sont plus dans le manifeste (phrase retirée, ancienne prise), puis garde le
 * manifeste. Un fichier qui n'arrive pas est réessayé à la prochaine synchro.
 */
export async function synchroniser(
    caches: CachesLike,
    identity: string,
    chercher: (url: string) => Promise<Response>,
    simultanes = 3,
): Promise<AudioManifest | null> {
    await purgerLesAutres(caches, identity);

    const reponse = await chercher(MANIFEST_URL);

    if (!reponse.ok) {
        return null;
    }

    const manifest = (await reponse.json()) as AudioManifest;
    const cache = await caches.open(cacheName(identity));
    const voulues = new Set(toutesLesAdresses(manifest).map(cleDe));

    for (const requete of await cache.keys()) {
        const cle = cleDe(requete.url);

        if (cle !== MANIFEST_URL && !voulues.has(cle)) {
            await cache.delete(requete.url);
        }
    }

    const manquantes: string[] = [];

    for (const url of toutesLesAdresses(manifest)) {
        if (!(await cache.match(url))) {
            manquantes.push(url);
        }
    }

    const file = [...manquantes];
    const travailleurs = Array.from(
        { length: Math.min(simultanes, file.length) },
        async () => {
            for (
                let url = file.shift();
                url !== undefined;
                url = file.shift()
            ) {
                try {
                    const fichier = await chercher(url);

                    if (fichier.ok) {
                        await cache.put(url, fichier);
                    }
                } catch {
                    // Réseau coupé en route : ce fichier sera repris à la prochaine synchro.
                }
            }
        },
    );
    await Promise.all(travailleurs);

    await cache.put(MANIFEST_URL, new Response(JSON.stringify(manifest)));

    return manifest;
}

export type Lisible = { url: string; liberer: () => void };

/**
 * Adresse à jouer pour un morceau : celle du cache s'il y est (lecture
 * immédiate, même hors connexion), sinon l'adresse du serveur.
 */
export async function lisible(
    caches: CachesLike,
    identity: string,
    url: string,
    creer: (blob: Blob) => string = (blob) => URL.createObjectURL(blob),
    liberer: (url: string) => void = (objet) => URL.revokeObjectURL(objet),
): Promise<Lisible> {
    try {
        const cache = await caches.open(cacheName(identity));
        const trouve = await cache.match(url);

        if (trouve) {
            const objet = creer(await trouve.blob());

            return { url: objet, liberer: () => liberer(objet) };
        }
    } catch {
        // Cache indisponible : on joue depuis le réseau.
    }

    return { url, liberer: () => undefined };
}
