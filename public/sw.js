/*
 * Service worker DIABA GESCOM (docs/08).
 *
 * - Ressources de build (noms hachés, immuables) : cache d'abord.
 * - Vitrine publique d'une boutique : réseau d'abord, dernière version en
 *   cache sinon. Les photos des articles de la vitrine sont gardées pour
 *   être revues hors ligne.
 * - Vente comptoir (/sales/pos) et file de synchronisation (/sync) : réseau
 *   d'abord, dernière version en cache sinon — mais le cache est partitionné
 *   par identité (compte + entreprise) et jamais restitué pour une identité
 *   différente ou inconnue (audit 2026-10-15, « Pages authentifiées mises en
 *   cache sans isolation utilisateur/tenant »).
 * - `/dashboard` et toute autre page privée ne sont jamais mises en cache
 *   avec des props authentifiées : en cas d'échec réseau, seul le shell
 *   neutre `/offline.html` (sans donnée de compte) est restitué.
 * - Aucune réponse d'API ni requête Inertia n'est mise en cache ; aucune
 *   requête autre que GET n'est interceptée.
 * - Les ventes hors ligne sont dans IndexedDB, pas ici : une mise à jour du
 *   service worker ne les touche jamais.
 */
importScripts('/sw-helpers.js');

const Sw = self.SwHelpers;

// Invalide les anciens bundles hachés après le déploiement du 19 octobre 2026.
const VERSION = 'diaba-v4';
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;
const PAGE_CACHE = `${VERSION}-pages`;
const IDENTITY_KEY_URL = '/__identity__';
const SHELL = [
    '/offline.html',
    '/manifest.webmanifest',
    '/brand/diaba-icon.svg',
    '/brand/icon-192.png',
    '/brand/logo-diaba-gescom-horizontal.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL)),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => !key.startsWith(VERSION))
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();

        return;
    }

    if (event.data && event.data.type === 'diaba-identity') {
        event.waitUntil(setIdentity(event.data.key ?? null));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (
        /^\/boutique\/[^/]+\/products\/[0-9a-f-]{36}\/image$/.test(url.pathname)
    ) {
        event.respondWith(staleWhileRevalidate(request));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigation(request, url));
    }
});

async function cacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        (await caches.open(ASSET_CACHE)).put(request, response.clone());
    }

    return response;
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((response) => {
            if (response.ok) {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => undefined);

    return cached ?? (await network) ?? Response.error();
}

/** Identité (compte + entreprise) connue du service worker, ou `null`. */
async function getIdentity() {
    const cache = await caches.open(PAGE_CACHE);
    const match = await cache.match(IDENTITY_KEY_URL);

    if (!match) {
        return null;
    }

    const data = await match.json();

    return data.identity ?? null;
}

/**
 * Déclarée par le client à chaque navigation et à la déconnexion. Purge
 * strictement toute page privée mise en cache sous une autre identité (ou
 * toutes, à la déconnexion où `identity` est `null`) — jamais seulement par
 * chemin.
 */
async function setIdentity(identity) {
    const cache = await caches.open(PAGE_CACHE);
    await cache.put(
        IDENTITY_KEY_URL,
        new Response(JSON.stringify({ identity })),
    );

    const keys = await cache.keys();
    await Promise.all(
        keys
            .filter((request) => Sw.shouldPurgeEntry(request.url, identity))
            .map((request) => cache.delete(request)),
    );
}

async function navigation(request, url) {
    const storefront = Sw.isStorefrontPage(url.pathname, url.search);
    const privatePage = Sw.isPrivateOfflinePage(url.pathname);

    try {
        const response = await fetch(request);

        // Seules les pages utiles hors ligne sont conservées, et seulement si
        // c'est bien la page demandée (pas une redirection vers la connexion).
        if (response.ok && !response.redirected) {
            if (storefront) {
                (await caches.open(PAGE_CACHE)).put(
                    url.pathname,
                    response.clone(),
                );
            } else if (privatePage) {
                const identity = await getIdentity();

                // Jamais de page privée en cache sans identité connue : une
                // clé fondée sur le seul chemin resterait valable pour tout
                // compte qui se connecterait ensuite sur cet appareil.
                if (identity) {
                    (await caches.open(PAGE_CACHE)).put(
                        Sw.privatePageCacheKey(url.pathname, identity),
                        response.clone(),
                    );
                }
            }
        }

        return response;
    } catch {
        if (storefront) {
            return (
                (await caches.match(url.pathname)) ??
                (await caches.match('/offline.html')) ??
                Response.error()
            );
        }

        if (privatePage) {
            const identity = await getIdentity();
            const cached = identity
                ? await caches.match(
                      Sw.privatePageCacheKey(url.pathname, identity),
                  )
                : undefined;

            if (cached) {
                return cached;
            }
        }

        // `/dashboard` et toute page privée sans identité confirmée : jamais
        // de contenu authentifié figé hors ligne, seulement le shell neutre.
        return (await caches.match('/offline.html')) ?? Response.error();
    }
}
