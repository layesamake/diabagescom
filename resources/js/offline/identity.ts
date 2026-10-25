import { router } from '@inertiajs/vue3';

/**
 * Déclare au service worker l'identité (compte + entreprise) courante, pour
 * partitionner et purger strictement le cache des pages privées (audit
 * 2026-10-15, « Pages authentifiées mises en cache sans isolation
 * utilisateur/tenant »). Sans appel, le service worker ne restitue jamais de
 * page privée en cache : il se replie sur le shell neutre.
 */
export function identityKey(userId: string | number, tenantId: string): string {
    return `${userId}:${tenantId}`;
}

function postToServiceWorker(message: unknown): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    navigator.serviceWorker.controller?.postMessage(message);
}

export function announceIdentity(
    userId: string | number | null,
    tenantId: string | null,
): void {
    postToServiceWorker({
        type: 'diaba-identity',
        key:
            userId !== null && tenantId !== null
                ? identityKey(userId, tenantId)
                : null,
    });
}

/** À appeler à la déconnexion explicite : purge immédiate des pages privées. */
export function clearIdentity(): void {
    announceIdentity(null, null);
}

/**
 * Réémet l'identité courante à chaque navigation Inertia (y compris le
 * chargement initial), pour que le service worker n'ait jamais à deviner
 * quel compte est actif.
 */
export function watchIdentity(): void {
    router.on('navigate', (event) => {
        const props = event.detail.page.props;
        const auth = props.auth as
            | { user?: { id: string | number } }
            | undefined;
        const context = props.context as
            | { tenant?: { id: string } }
            | null
            | undefined;

        announceIdentity(auth?.user?.id ?? null, context?.tenant?.id ?? null);
    });
}
