import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const caddyfile = readFileSync(
    new URL('../../deploy/Caddyfile', import.meta.url),
    'utf8',
);
const compose = readFileSync(
    new URL('../../deploy/compose.yaml', import.meta.url),
    'utf8',
);
const deploy = readFileSync(
    new URL('../../deploy/deploy.sh', import.meta.url),
    'utf8',
);

void test('the edge proxy targets the environment-specific nginx alias', () => {
    assert.match(caddyfile, /reverse_proxy \{\$DIABA_ENV\}-nginx:8080/);
    assert.match(compose, /aliases: \["\$\{DIABA_ENV\}-nginx"\]/);
    assert.match(compose, /DIABA_ENV: \$\{DIABA_ENV:\?\}/);
    assert.match(compose, /profiles: \[edge\]/);
    assert.match(compose, /external: \$\{DIABA_EDGE_EXTERNAL:-false\}/);
    assert.match(
        compose,
        /name: \$\{DIABA_EDGE_NETWORK:\?DIABA_EDGE_NETWORK requis\}/,
    );
});

void test('a deployment reloads its owned edge proxy after nginx replacement', () => {
    assert.match(deploy, /COMPOSE_ARGS=\(\)/);
    assert.match(deploy, /COMPOSE_ARGS=\(--profile edge\)/);
    assert.match(deploy, /compose ps -q caddy/);
    assert.match(deploy, /caddy reload/);
    assert.match(
        deploy,
        /--config \/etc\/caddy\/Caddyfile --adapter caddyfile/,
    );
});

void test('a failed proxy reload enters the rollback path', () => {
    assert.match(deploy, /rollback_deployment\(\)/);
    assert.match(deploy, /if ! reload_edge_proxy; then/);
    assert.match(deploy, /rollback_deployment \|\| true/);
});
