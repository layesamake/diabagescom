import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    canonicalPayload,
    decideEnqueue,
    unsentCommandCount,
} from '../../resources/js/offline/commandGuard.ts';

/*
 * Audit 2026-10-15 : « collision locale de command_id » (une vente en file écrasée par
 * une autre) et « pertes de commandes lors de la déconnexion » (commandes à traiter non
 * comptées dans l'avertissement).
 */

void test('the canonical form ignores key order and undefined values', () => {
    assert.equal(
        canonicalPayload({ b: 1, a: [{ y: 2, x: 1 }], c: undefined }),
        canonicalPayload({ a: [{ x: 1, y: 2 }], b: 1 }),
    );
    assert.notEqual(canonicalPayload({ a: 1 }), canonicalPayload({ a: 2 }));
});

void test('a new command id is created', () => {
    assert.equal(decideEnqueue(undefined, { lines: [] }), 'create');
});

void test('the same id with the same content is reused, never duplicated', () => {
    const payload = {
        lines: [{ product_id: 'p', quantity: '2' }],
        tenders: [],
    };
    assert.equal(
        decideEnqueue(
            {
                payload: {
                    tenders: [],
                    lines: [{ quantity: '2', product_id: 'p' }],
                },
            },
            payload,
        ),
        'reuse',
    );
});

void test('the same id with another content is a conflict, the first basket is kept', () => {
    assert.equal(
        decideEnqueue(
            { payload: { lines: [{ product_id: 'p', quantity: '2' }] } },
            { lines: [{ product_id: 'p', quantity: '20' }] },
        ),
        'conflict',
    );
});

void test('logout counts queued and to-review commands together', () => {
    assert.equal(unsentCommandCount({ pending: 0, attention: 0 }), 0);
    assert.equal(unsentCommandCount({ pending: 0, attention: 1 }), 1);
    assert.equal(unsentCommandCount({ pending: 2, attention: 3 }), 5);
});
