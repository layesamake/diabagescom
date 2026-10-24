import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('resources/js/pages/simple/Resultats.vue', 'utf8');
const template = source.slice(
    source.indexOf('<template>'),
    source.indexOf('</template>'),
);

void test('Mes résultats permet de choisir semaine, mois ou année', () => {
    assert.match(template, /v-for="option in periods"/);
    assert.match(
        source,
        /router\.get\('\/simple\/resultats', \{ period: key \}\)/,
    );
    assert.match(source, /label: 'Semaine'/);
    assert.match(source, /label: 'Mois'/);
    assert.match(source, /label: 'Année'/);
});

void test('Mes résultats montre où sont les encaissements', () => {
    assert.match(template, /Où est l'argent \?/);
    assert.match(template, /v-for="entry in by_method"/);
    assert.match(template, /formatFranc\(entry\.total\)/);
});
