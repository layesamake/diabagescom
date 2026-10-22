import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

void test('EnTeteEcran reflète immédiatement les événements navigateur hors ligne', () => {
    const src = readFileSync(
        'resources/js/components/simple/EnTeteEcran.vue',
        'utf8',
    );

    assert.match(
        src,
        /\bonMounted\b/,
        'le composant doit écouter le navigateur',
    );
    assert.match(
        src,
        /\bonBeforeUnmount\b/,
        'les écouteurs doivent être retirés',
    );
    assert.match(src, /window\.addEventListener\('online'/);
    assert.match(src, /window\.addEventListener\('offline'/);
    assert.match(src, /window\.removeEventListener\('online'/);
    assert.match(src, /window\.removeEventListener\('offline'/);
    assert.match(
        src,
        /v-if="horsLigne\s*\|\|\s*navigateurHorsLigne"/,
        'CloudOff doit dépendre de l’état réseau réel',
    );
});
