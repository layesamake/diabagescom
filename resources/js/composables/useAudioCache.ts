import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, watch } from 'vue';
import {
    lireManifest,
    synchroniser,
    type AudioManifest,
    type CachesLike,
} from '@/lib/audioCache';
import { identityKey } from '@/offline/identity';

// Dernier manifeste lu ou téléchargé : évite de relire le cache à chaque appui.
let enMemoire: { identite: string; manifest: AudioManifest } | null = null;

export function cachesDisponibles(): CachesLike | null {
    return typeof caches === 'undefined'
        ? null
        : (caches as unknown as CachesLike);
}

/** Compte + entreprise courants, comme pour le cache des pages (service worker). */
export function identiteCourante(
    props: Record<string, unknown>,
): string | null {
    const utilisateur = (
        props.auth as { user?: { id: string | number } } | null
    )?.user?.id;
    const entreprise = (props.context as { tenant?: { id: string } } | null)
        ?.tenant?.id;

    return utilisateur !== undefined && entreprise !== undefined
        ? identityKey(utilisateur, entreprise)
        : null;
}

export async function manifestPour(
    identite: string,
): Promise<AudioManifest | null> {
    if (enMemoire?.identite === identite) {
        return enMemoire.manifest;
    }

    const stock = cachesDisponibles();

    if (!stock) {
        return null;
    }

    const manifest = await lireManifest(stock, identite);

    if (manifest) {
        enMemoire = { identite, manifest };
    }

    return manifest;
}

/**
 * Garde les messages audio de l'entreprise sur le téléphone, en tâche de fond,
 * dès qu'il y a du réseau : à l'ouverture, au retour d'internet et quand les
 * phrases enregistrées changent. Sans voix enregistrée, rien n'est téléchargé.
 */
export function useAudioPrefetch(): void {
    const page = usePage();
    let enCours = false;

    const disponibles = (): string[] =>
        (page.props.audio as { disponibles: string[] } | null)?.disponibles ??
        [];

    async function lancer(): Promise<void> {
        const stock = cachesDisponibles();
        const identite = identiteCourante(page.props);

        if (
            !stock ||
            !identite ||
            disponibles().length === 0 ||
            !navigator.onLine ||
            enCours
        ) {
            return;
        }

        enCours = true;

        try {
            const manifest = await synchroniser(stock, identite, (url) =>
                fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                }),
            );

            if (manifest) {
                enMemoire = { identite, manifest };
            }
        } catch {
            // Hors ligne en cours de route : on reprendra au retour du réseau.
        } finally {
            enCours = false;
        }
    }

    const relancer = (): void => void lancer();

    onMounted(() => {
        relancer();
        window.addEventListener('online', relancer);
    });
    onBeforeUnmount(() => window.removeEventListener('online', relancer));
    watch(() => disponibles().join(','), relancer);
}
