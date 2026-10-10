import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { AppContext } from '@/types';

/**
 * Contexte entreprise/dépôt résolu par le serveur.
 *
 * `can()` ne sert qu'à adapter l'affichage (menus, boutons) : le serveur
 * revérifie chaque action et reste la seule barrière de sécurité.
 */
export function useAppContext() {
    const page = usePage();

    const context = computed<AppContext | null>(
        () => (page.props.context as AppContext | null | undefined) ?? null,
    );

    const can = (permission: string): boolean =>
        context.value?.permissions.includes(permission) ?? false;

    return { context, can };
}
