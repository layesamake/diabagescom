import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// L'écran Voix du mode simple enregistre avec MediaRecorder : le micro doit être
// autorisé pour l'application elle-même (jamais pour des sites tiers).
const politique = readFileSync(
    'docker/nginx/security-headers.conf',
    'utf8',
).match(/add_header Permissions-Policy "([^"]+)"/)?.[1];

void test('Permissions-Policy autorise le micro pour l’application seulement', () => {
    assert.ok(politique, 'la politique doit être définie');
    assert.match(politique, /\bmicrophone=\(self\)/);
    assert.doesNotMatch(politique, /microphone=\(\)|microphone=\*/);
});

void test('Permissions-Policy garde la caméra pour l’application et refuse la localisation', () => {
    assert.ok(politique);
    assert.match(politique, /\bcamera=\(self\)/);
    assert.match(politique, /\bgeolocation=\(\)/);
});
