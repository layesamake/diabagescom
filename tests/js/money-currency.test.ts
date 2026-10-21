import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Règle 4 du cahier des charges : « 45 000 F », espace pour les milliers,
// pas de décimales, jamais « XOF ».
void test("l'unité monétaire affichée est le franc « F » et jamais XOF", () => {
    const money = readFileSync('resources/js/lib/money.ts', 'utf8');

    assert.doesNotMatch(money, /`\s*\$\{[^}]+\}\s*XOF\s*`/);
    assert.match(money, /`\s*\$\{[^}]+\}\s*F\s*`/);
});
