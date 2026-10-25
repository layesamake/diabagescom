/*
 * Fonctions pures du service worker DIABA GESCOM, isolées de `self`/`caches`
 * pour être testées sans navigateur (voir tests/js/sw-helpers.test.js).
 *
 * Correction de l'audit 2026-10-15 ("Pages authentifiées mises en cache sans
 * isolation utilisateur/tenant") :
 * - `/dashboard` n'est plus une page privée mise en cache hors ligne : jamais
 *   de props authentifiées figées, seulement le shell neutre `/offline.html`.
 * - `/sales/pos`, `/simple/vendre` (mode simple) et `/sync` restent utilisables
 *   hors ligne (besoin réel de vente comptoir sans réseau), mais leur cache est partitionné par identité
 *   (compte + entreprise) et jamais restitué pour une identité différente ou
 *   inconnue.
 */
const PRIVATE_OFFLINE_PAGES = ['/sales/pos', '/simple/vendre', '/sync'];
const STOREFRONT_PAGE = /^\/boutique\/[^/]+\/?$/;
const PRIVATE_PAGE_PREFIX = '/__private-page__';

function isPrivateOfflinePage(pathname) {
    return PRIVATE_OFFLINE_PAGES.includes(pathname);
}

function isStorefrontPage(pathname, search) {
    return STOREFRONT_PAGE.test(pathname) && search === '';
}

/** Clé de cache d'une page privée : jamais le chemin seul, toujours avec l'identité. */
function privatePageCacheKey(pathname, identity) {
    return `${PRIVATE_PAGE_PREFIX}${pathname}?identity=${encodeURIComponent(identity)}`;
}

/** Identité portée par une clé de cache de page privée, ou `null` si ce n'en est pas une. */
function identityFromCacheKey(url) {
    const path = url.startsWith('http')
        ? new URL(url).pathname + new URL(url).search
        : url;

    if (!path.startsWith(PRIVATE_PAGE_PREFIX)) {
        return null;
    }

    const query = path.slice(path.indexOf('?') + 1);

    return new URLSearchParams(query).get('identity');
}

/**
 * Une entrée de page privée doit être purgée si elle ne porte pas l'identité
 * courante (changement de compte/entreprise, ou déconnexion où `currentIdentity`
 * est `null`). Les entrées non partitionnées (vitrine, marqueur d'identité) ne
 * sont jamais concernées par cette purge.
 */
function shouldPurgeEntry(url, currentIdentity) {
    const entryIdentity = identityFromCacheKey(url);

    if (entryIdentity === null) {
        return false;
    }

    return entryIdentity !== currentIdentity;
}

const api = {
    PRIVATE_OFFLINE_PAGES,
    STOREFRONT_PAGE,
    PRIVATE_PAGE_PREFIX,
    isPrivateOfflinePage,
    isStorefrontPage,
    privatePageCacheKey,
    identityFromCacheKey,
    shouldPurgeEntry,
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
} else {
    self.SwHelpers = api;
}
