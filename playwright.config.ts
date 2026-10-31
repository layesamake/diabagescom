import { defineConfig, devices } from '@playwright/test';

/**
 * Tests de bout en bout du mode hors connexion. Ils tournent contre
 * l'application locale (Docker) avec le compte de démonstration :
 * `docker compose exec app php artisan diaba:demo`.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 300_000,
    expect: { timeout: 60_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        serviceWorkers: 'allow',
    },
    projects: [
        {
            name: 'mobile',
            use: { ...devices['Pixel 7'] },
        },
    ],
});
