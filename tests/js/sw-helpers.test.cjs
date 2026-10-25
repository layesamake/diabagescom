const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const helpers = require(
    path.join(__dirname, '..', '..', 'public', 'sw-helpers.js'),
);

/*
 * Vérifie, sans navigateur, les règles de l'audit 2026-10-15 (« Pages
 * authentifiées mises en cache sans isolation utilisateur/tenant ») :
 * `/dashboard` n'est jamais une page privée mise en cache, et les pages qui
 * le sont (`/sales/pos`, `/sync`) sont partitionnées par identité, jamais
 * restituées pour un autre compte/entreprise.
 */

void test('dashboard is never a privately cacheable offline page', () => {
    assert.equal(helpers.isPrivateOfflinePage('/dashboard'), false);
});

void test('pos, simple sell and sync are the only private offline-capable pages', () => {
    assert.deepEqual(helpers.PRIVATE_OFFLINE_PAGES, [
        '/sales/pos',
        '/simple/vendre',
        '/sync',
    ]);
    assert.equal(helpers.isPrivateOfflinePage('/sales/pos'), true);
    assert.equal(helpers.isPrivateOfflinePage('/simple/vendre'), true);
    assert.equal(helpers.isPrivateOfflinePage('/simple/caisse'), false);
    assert.equal(helpers.isPrivateOfflinePage('/simple/ventes'), false);
    assert.equal(helpers.isPrivateOfflinePage('/sync'), true);
    assert.equal(helpers.isPrivateOfflinePage('/settings'), false);
});

void test('private page cache keys are partitioned by identity, not by path alone', () => {
    const keyUserA = helpers.privatePageCacheKey('/sales/pos', '1:tenant-a');
    const keyUserB = helpers.privatePageCacheKey('/sales/pos', '2:tenant-b');

    assert.notEqual(keyUserA, keyUserB);
    assert.equal(helpers.identityFromCacheKey(keyUserA), '1:tenant-a');
    assert.equal(helpers.identityFromCacheKey(keyUserB), '2:tenant-b');
});

void test('a page cached under one identity is purged on a different identity', () => {
    const forUserA = helpers.privatePageCacheKey('/sales/pos', '1:tenant-a');
    const forUserB = helpers.privatePageCacheKey('/sync', '2:tenant-b');

    assert.equal(helpers.shouldPurgeEntry(forUserB, '1:tenant-a'), true);
    assert.equal(helpers.shouldPurgeEntry(forUserA, '1:tenant-a'), false);
});

void test('logout (identity = null) purges every private page entry', () => {
    const entry = helpers.privatePageCacheKey('/sales/pos', '1:tenant-a');

    assert.equal(helpers.shouldPurgeEntry(entry, null), true);
});

void test('non-partitioned entries (storefront pages, identity marker) are left alone', () => {
    assert.equal(
        helpers.shouldPurgeEntry('/boutique/ma-boutique', '1:tenant-a'),
        false,
    );
    assert.equal(
        helpers.shouldPurgeEntry('/__identity__', '1:tenant-a'),
        false,
    );
});

void test('storefront pages are identified without an authenticated identity', () => {
    assert.equal(helpers.isStorefrontPage('/boutique/ma-boutique', ''), true);
    assert.equal(
        helpers.isStorefrontPage('/boutique/ma-boutique', '?page=2'),
        false,
    );
    assert.equal(helpers.isStorefrontPage('/dashboard', ''), false);
});
