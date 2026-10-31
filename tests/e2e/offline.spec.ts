import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * Mode hors ligne du point de vente : une vente faite sans réseau reste dans la
 * file locale, puis part vers le serveur au retour de la connexion.
 *
 * Prérequis : compte de démonstration (`php artisan diaba:demo`), caisse ouverte,
 * au moins un article en stock.
 */
test.describe('mode hors ligne', () => {
    test('une vente hors connexion est envoyée au retour du réseau', async ({
        page,
        context,
    }) => {
        await login(page);
        await page.goto('/sales/pos');

        // Le catalogue doit être en cache avant de couper le réseau.
        await expect(page.getByLabel(/^En ligne/)).toBeVisible({
            timeout: 30_000,
        });
        const tile = page
            .locator('button')
            .filter({ hasText: /stock \d/ })
            .first();
        await expect(tile).toBeVisible({ timeout: 30_000 });
        await tile.click();

        await context.setOffline(true);
        await expect(page.getByLabel(/^Hors connexion/)).toBeVisible({
            timeout: 15_000,
        });

        await page
            .getByRole('button', { name: /Enregistrer hors connexion/ })
            .click();

        // Le badge de synchronisation compte la vente en attente (la page /sync, elle, est serveur).
        await expect(
            page.getByLabel(/^Hors connexion · \d+ vente\(s\) en attente/),
        ).toBeVisible({ timeout: 15_000 });

        await context.setOffline(false);
        await page.goto('/sync');
        await expect(page.getByText('En attente d’envoi').first()).toBeVisible({
            timeout: 60_000,
        });
        await page
            .getByRole('button', { name: 'Synchroniser maintenant' })
            .click();
        await expect(page.getByText('Validée').first()).toBeVisible({
            timeout: 60_000,
        });
    });

    test('le point de vente se recharge sans réseau', async ({
        page,
        context,
    }) => {
        await login(page);
        await page.goto('/sales/pos');
        await expect(page.getByLabel(/^En ligne/)).toBeVisible({
            timeout: 30_000,
        });

        await context.setOffline(true);
        await page.reload();

        // Selon que la page est déjà en cache, le rechargement affiche la vente comptoir
        // directement, ou le repli de l'application (à partir duquel on peut l'ouvrir).
        const pos = page.getByRole('heading', {
            name: 'Vente comptoir',
            exact: true,
        });
        const fallback = page.getByRole('button', {
            name: 'Ouvrir la vente comptoir',
        });
        await expect(pos.or(fallback)).toBeVisible({ timeout: 60_000 });

        if (await fallback.isVisible()) {
            await fallback.click();
        }

        await expect(pos).toBeVisible({ timeout: 60_000 });
    });
});
