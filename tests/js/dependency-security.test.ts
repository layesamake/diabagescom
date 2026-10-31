import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const composerLock = JSON.parse(
    readFileSync(new URL('../../composer.lock', import.meta.url), 'utf8'),
) as { packages: Array<{ name: string; version: string }> };

void test('Symfony Mime includes the CVE-2026-45070 security fix', () => {
    const mime = composerLock.packages.find(
        (packageDefinition) => packageDefinition.name === 'symfony/mime',
    );

    assert.ok(mime, 'symfony/mime must be locked');

    const [major, minor, patch] = mime.version.slice(1).split('.').map(Number);

    assert.ok(
        major > 8 ||
            (major === 8 && (minor > 1 || (minor === 1 && patch >= 10))),
        `Expected symfony/mime >= v8.1.10, got ${mime.version}`,
    );
});
