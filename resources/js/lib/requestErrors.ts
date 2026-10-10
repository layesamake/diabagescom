import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { t } from '@/i18n';

/**
 * Erreurs techniques (serveur indisponible, délai dépassé, perte de réseau) :
 * un message clair au lieu de la page d'erreur brute.
 *
 * Les opérations sensibles portent une clé d'idempotence : l'utilisateur peut
 * réessayer sans risque de doublon, ce que le message lui indique.
 */
export function initializeRequestErrors(): void {
    router.on('httpException', (event) => {
        const status = event.detail.response.status;

        // 4xx « métier » (403, 404, 419…) : laisser Inertia/Laravel les gérer.
        if (status < 500) {
            return;
        }

        event.preventDefault();
        toast.error(t('errors.server', { status }), { duration: 10000 });
    });

    router.on('networkError', (event) => {
        event.preventDefault();
        toast.error(t('errors.network'), { duration: 10000 });
    });
}
