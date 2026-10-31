import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const routes = readFileSync(
    new URL('../../routes/web.php', import.meta.url),
    'utf8',
);

void test('shared documents require a signed URL', () => {
    assert.match(
        routes,
        /Route::get\('d\/\{document\}', \[DocumentShareController::class, 'show'\]\)->whereUuid\('document'\)\s*->middleware\(\['signed', 'throttle:30,1'\]\)->name\('documents\.shared'\);/s,
    );
});

void test('the temporary diagnostic controller is removed', () => {
    assert.equal(
        existsSync(
            new URL(
                '../../app/Http/Controllers/DiagnosticController.php',
                import.meta.url,
            ),
        ),
        false,
    );
});

void test('billing webhook constraints remain chained to its route', () => {
    assert.match(
        routes,
        /Route::post\('webhooks\/billing\/\{provider\}', WebhookController::class\)\s*->where\('provider', '\[a-z_\]\+'\)\s*->middleware\('throttle:120,1'\)\s*->name\('webhooks\.billing'\);/s,
    );
});

void test('temporary diagnostic endpoints are not publicly routed', () => {
    assert.doesNotMatch(
        routes,
        /diagnostic\/(trial-extend(?:-test)?|recent-errors|extend-trial-errors)/,
    );
});
