/*
 * Service worker DIABA GESCOM (docs/08).
 *
 * - Ressources de build (noms hachés, immuables) : cache d'abord.
 * - Pages utiles hors connexion (vente comptoir, file de synchronisation,
 *   accueil, vitrine publique d'une boutique) : réseau d'abord, dernière
 *   version en cache sinon. Les photos des articles de la vitrine sont
 *   gardées pour être revues hors ligne.
 * - Aucune réponse d'API ni requête Inertia n'est mise en cache ; aucune
 *   requête autre que GET n'est interceptée.
 * - Les ventes hors ligne sont dans IndexedDB, pas ici : une mise à jour du
 *   service worker ne les touche jamais.
 */
const VERSION = 'diaba-v2';
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;
const PAGE_CACHE = `${VERSION}-pages`;
const OFFLINE_PAGES = ['/sales/pos', '/sync', '/dashboard'];
// Vitrine publique : /boutique/{slug} (page seule, jamais la confirmation d'une commande).
const STOREFRONT_PAGE = /^\/boutique\/[^/]+\/?$/;
const STOREFRONT_IMAGE = /^\/boutique\/[^/]+\/products\/[0-9a-f-]{36}\/image$/;
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

    if (STOREFRONT_IMAGE.test(url.pathname)) {
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

async function navigation(request, url) {
    const storefront = STOREFRONT_PAGE.test(url.pathname) && url.search === '';
    const offlineCapable = storefront || OFFLINE_PAGES.includes(url.pathname);

    try {
        const response = await fetch(request);

        // Seules les pages utiles hors ligne sont conservées, et seulement si
        // c'est bien la page demandée (pas une redirection vers la connexion).
        if (offlineCapable && response.ok && !response.redirected) {
            (await caches.open(PAGE_CACHE)).put(url.pathname, response.clone());
        }

        return response;
    } catch {
        const cached = offlineCapable
            ? await caches.match(url.pathname)
            : undefined;

        return (
            cached ?? (await caches.match('/offline.html')) ?? Response.error()
        );
    }
}
