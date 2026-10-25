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

void test('Mes résultats indique la variation des ventes depuis la période précédente', () => {
    assert.match(source, /comparison: \{ total:/);
    assert.match(template, /Par rapport à avant/);
    assert.match(template, /comparison\.total\.change/);
});

void test('Mes résultats liste les meilleures ventes avec quantités et montants', () => {
    assert.match(template, /v-for="product in top_products"/);
    assert.match(template, /\{\{ product\.label \}\}/);
    assert.match(template, /\{\{ product\.quantity \}\}/);
    assert.match(template, /formatFranc\(product\.total\)/);
});

void test('Mes résultats montre où sont les encaissements', () => {
    assert.match(template, /Où est l'argent \?/);
    assert.match(template, /v-for="entry in by_method"/);
    assert.match(template, /formatFranc\(entry\.total\)/);
});
