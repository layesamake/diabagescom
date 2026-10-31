import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Page } from '@playwright/test';

/**
 * Identifiants du compte de démonstration local (`php artisan diaba:demo`).
 * Ils ne servent qu'en local : le fichier est ignoré par Git et `baseURL`
 * doit pointer vers localhost.
 */
export function demoCredentials(): {
    email: string;
    password: string;
    totpSecret: string;
} {
    const file = resolve(
        process.cwd(),
        'storage/app/private/demo-credentials.txt',
    );
    const lines = readFileSync(file, 'utf8')
        .split('\n')
        .map((line) => line.trim());
    const [email, password] = lines.filter((line) => line !== '');
    const totp = lines.find((line) => line.startsWith('totp:')) ?? '';

    return { email, password, totpSecret: totp.replace(/^totp:/, '') };
}

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(input: string): Buffer {
    let bits = 0;
    let value = 0;
    const bytes: number[] = [];

    for (const char of input.replace(/=+$/, '').toUpperCase()) {
        value = (value << 5) | BASE32.indexOf(char);
        bits += 5;

        if (bits >= 8) {
            bytes.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(bytes);
}

/** Code TOTP (RFC 6238) à 6 chiffres, pas de 30 secondes. */
export function totpCode(secret: string, at = Date.now()): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const digest = createHmac('sha1', base32Decode(secret))
        .update(counter)
        .digest();
    const offset = digest[digest.length - 1] & 0x0f;
    const binary =
        ((digest[offset] & 0x7f) << 24) |
        (digest[offset + 1] << 16) |
        (digest[offset + 2] << 8) |
        digest[offset + 3];

    return String(binary % 1_000_000).padStart(6, '0');
}

/** Connexion complète, confirmation du mot de passe et double authentification comprises. */
export async function login(page: Page): Promise<void> {
    const { email, password, totpSecret } = demoCredentials();

    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page
        .getByRole('button', { name: 'Se connecter', exact: true })
        .click();

    // Pilotée par l'URL : on répond à l'écran où l'on se trouve jusqu'à l'accès à l'application.
    // L'environnement Docker local répond lentement, d'où les délais larges.
    const deadline = Date.now() + 240_000;

    while (Date.now() < deadline) {
        const path = new URL(page.url()).pathname;

        if (
            !path.startsWith('/login') &&
            !path.startsWith('/two-factor') &&
            !path.startsWith('/user/confirm-password')
        ) {
            return;
        }

        if (path.startsWith('/user/confirm-password')) {
            await page
                .locator('input[type="password"]')
                .first()
                .waitFor({ timeout: 60_000 });
            await page.fill('input[type="password"]', password);
            await page.keyboard.press('Enter');
            await page
                .waitForURL((url) => url.pathname !== path, {
                    timeout: 120_000,
                })
                .catch(() => {});
        } else if (path.startsWith('/two-factor')) {
            const code = page
                .locator('input[autocomplete="one-time-code"]')
                .first();
            await code.waitFor({ timeout: 60_000 });
            await code.fill(totpCode(totpSecret));
            await page.keyboard.press('Enter');
            await page
                .waitForURL((url) => url.pathname !== path, {
                    timeout: 120_000,
                })
                .catch(() => {});
        } else {
            await page.waitForTimeout(1_000);
        }
    }

    throw new Error(`Connexion impossible : toujours sur ${page.url()}`);
}
