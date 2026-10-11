import { toast } from 'vue-sonner';
import { t } from '@/i18n';

/**
 * Enregistre le service worker (build de production uniquement : en
 * développement, Vite sert les modules à la volée).
 *
 * Une nouvelle version n'est activée qu'avec l'accord de l'utilisateur : les
 * ventes en file sont dans IndexedDB et survivent à la mise à jour.
 */
export function registerServiceWorker(): void {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register(
                '/sw.js',
                { scope: '/' },
            );

            const offerUpdate = (worker: ServiceWorker): void => {
                toast(t('offline.update_available'), {
                    duration: Infinity,
                    action: {
                        label: t('offline.update_now'),
                        onClick: () => worker.postMessage('skip-waiting'),
                    },
                });
            };

            if (registration.waiting && navigator.serviceWorker.controller) {
                offerUpdate(registration.waiting);
            }

            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;

                worker?.addEventListener('statechange', () => {
                    if (
                        worker.state === 'installed' &&
                        navigator.serviceWorker.controller
                    ) {
                        offerUpdate(worker);
                    }
                });
            });

            let reloading = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (!reloading) {
                    reloading = true;
                    window.location.reload();
                }
            });
        } catch {
            // Pas de service worker (navigation privée…) : l'application reste utilisable en ligne.
        }
    });
}
