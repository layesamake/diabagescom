import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Contrat visuel du bandeau d'en-tête, dérivé des maquettes 03-premiers-pas,
// 08a-clients et 09-ajouter-article : fond vert foncé, retour + icône +
// titre à gauche, soit une pastille d'étape (« 2 / 11 ») en orange, soit un
// bouton HP — jamais les deux en même temps, jamais le compteur si pas
// d'étape en cours.
void test('le bandeau expose une pastille d étape quand etape/etapeTotal sont fournis', () => {
    const src = readFileSync(
        'resources/js/components/simple/EnTeteEcran.vue',
        'utf8',
    );

    assert.match(src, /etape\s*\??:\s*number/, 'prop etape manquante');
    assert.match(
        src,
        /etapeTotal\s*\??:\s*number/,
        'prop etapeTotal manquante',
    );
    assert.match(
        src,
        /var\(--s-orange\)/,
        'la pastille doit utiliser --s-orange',
    );
    assert.match(
        src,
        /\{\{[^}]*etape[^}]*\}\}\s*\/\s*\{\{[^}]*etapeTotal[^}]*\}\}/,
        'format « n / N » attendu dans la pastille',
    );
});

void test('la pastille d étape et le bouton HP ne coexistent pas', () => {
    const src = readFileSync(
        'resources/js/components/simple/EnTeteEcran.vue',
        'utf8',
    );
    const debut = src.indexOf('<template>');
    const fin = src.indexOf('</template>');
    assert.ok(debut !== -1 && fin !== -1);
    const region = src.slice(debut, fin);
    assert.match(region, /afficherPastille/);
    // le rendu du bouton HP doit explicitement exclure la pastille d'étape
    const bouton = region.match(/<BoutonHautParleur[\s\S]{0,400}?\/>/);
    assert.ok(bouton, 'BoutonHautParleur absent du bandeau');
    assert.match(
        bouton[0],
        /afficherPastille/,
        'le bouton HP doit vérifier afficherPastille',
    );
});
