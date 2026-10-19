import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/Welcome.vue', import.meta.url),
    'utf8',
);

void test('the desktop hero uses the reference logo and 1200px composition', () => {
    for (const marker of [
        'House,',
        'data-landing="brand"',
        'max-w-[1200px]',
        'data-landing="hero-copy"',
        'max-w-[640px]',
        'data-landing="phone"',
        'xl:grid-cols-[640px_1fr]',
        'xl:translate-x-4',
    ]) {
        assert.ok(
            page.includes(marker),
            `missing desktop reference marker: ${marker}`,
        );
    }
});

void test('the header and hero preserve an accessible tablet layout', () => {
    for (const marker of [
        'lg:flex',
        'lg:hidden',
        'xl:grid-cols-[640px_1fr]',
        'xl:gap-14',
    ]) {
        assert.ok(
            page.includes(marker),
            `missing responsive marker: ${marker}`,
        );
    }

    assert.ok(
        !page.includes('lg:grid-cols-[640px_1fr]'),
        'the fixed hero composition must not start at 1024px',
    );
});
