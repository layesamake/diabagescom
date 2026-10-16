import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const swPath = require.resolve('../../public/sw.js');
const helpersPath = require.resolve('../../public/sw-helpers.js');

void test('the service worker evaluates after importing its helpers', () => {
    let context: vm.Context;

    const sandbox = {
        caches: {},
        console,
        Response: { error: () => ({}) },
        URL,
        URLSearchParams,
        fetch: () => Promise.resolve(),
        self: { addEventListener: () => undefined },
        importScripts: (...paths: string[]) => {
            for (const path of paths) {
                vm.runInContext(
                    readFileSync(
                        require.resolve(`../../public${path}`),
                        'utf8',
                    ),
                    context,
                    { filename: path },
                );
            }
        },
    };

    context = vm.createContext(sandbox);

    assert.doesNotThrow(() => {
        vm.runInContext(readFileSync(swPath, 'utf8'), context, {
            filename: swPath,
        });
    });
    assert.equal(
        typeof (sandbox.self as { SwHelpers?: unknown }).SwHelpers,
        'object',
    );
    assert.equal(
        typeof (sandbox.self as { addEventListener?: unknown })
            .addEventListener,
        'function',
    );

    // The helper file is loaded by sw.js; keep the direct path referenced so the
    // test fails loudly if the public helper disappears.
    assert.ok(helpersPath.endsWith('public/sw-helpers.js'));
});
