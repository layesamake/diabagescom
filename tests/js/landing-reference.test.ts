import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const landing = readFileSync(
    new URL('../../resources/js/pages/Welcome.vue', import.meta.url),
    'utf8',
);
const marketingConfig = readFileSync(
    new URL('../../config/diaba.php', import.meta.url),
    'utf8',
);

void test('la landing porte la promesse simple et le WhatsApp commercial officiel', () => {
    assert.match(landing, /Vendez en 3 gestes\./);
    assert.match(landing, /Même sans savoir bien lire\./);
    assert.match(landing, /Essayer.*trialDays.*jours gratuits/s);
    assert.match(landing, /https:\/\/wa\.me\/\$\{props\.contact\.whatsapp\}/);
    assert.match(
        marketingConfig,
        /'whatsapp' => env\('DIABA_SALES_WHATSAPP', '221768645834'\)/,
    );
});

void test('la landing reprend toutes les sections et actions de la maquette de référence', () => {
    for (const expected of [
        'Comment ça marche',
        'Fait pour :',
        'Une vente, c’est 3 gestes. Pas plus.',
        'Pensé pour tout le monde',
        'Du comptoir au comptable, sans rien recopier.',
        'Moins cher qu’une erreur de caisse par mois.',
        'Vos questions',
        'Ce soir, sachez exactement combien vous avez gagné.',
    ]) {
        assert.match(landing, new RegExp(expected));
    }

    assert.match(landing, /id="comment"/);
    assert.match(landing, /id="prix"/);
    assert.match(landing, /id="questions"/);
    assert.match(landing, /id="essai"/);
    assert.match(landing, /v-for="plan in plans"/);
    assert.match(landing, /md:hidden/);
});

void test('la landing conserve tous les canaux et tarifs configurés', () => {
    assert.match(landing, /price_yearly: number \| null/);
    assert.match(landing, /addons:/);
    assert.match(landing, /v-for="\(addon, index\) in addons"/);
    assert.match(landing, /formatWhatsappNumber/);
    assert.match(landing, /formatWhatsappNumber\(contact\.whatsapp\)/);
    assert.match(landing, /mailto:\$\{contact\.email\}/);
    assert.match(landing, /function planPeriod\(plan: Plan\): 'an' \| 'mois'/);
    assert.match(
        landing,
        /function addonPeriod\(addon: Addon\): 'an' \| 'mois'/,
    );
});
