import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import { initOffline } from '@/offline/sync';

/**
 * Prépare l'appareil hors connexion (base locale, enregistrement de l'appareil,
 * catalogue, envoi de la file) pour l'utilisateur et l'entreprise courants.
 * Utilisé par le cadre du mode expert (via SyncBadge) et par celui du mode
 * simple : sans lui, une vente faite sans réseau n'aurait nulle part où attendre.
 */
export function useOfflineStart(): void {
    const page = usePage();
    const { context, can } = useAppContext();
    const enabled = computed(
        () => Boolean(context.value) && can('sale.create'),
    );

    function start(): void {
        if (enabled.value && context.value) {
            void initOffline({
                userId: page.props.auth.user.id,
                tenantId: context.value.tenant.id,
                warehouseId: context.value.warehouse?.id ?? null,
            });
        }
    }

    onMounted(start);
    watch(
        () => [context.value?.tenant.id, context.value?.warehouse?.id],
        start,
    );
}
