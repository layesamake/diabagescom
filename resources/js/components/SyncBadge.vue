<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CloudOff, RefreshCw, TriangleAlert, Wifi } from '@lucide/vue';
import { computed, onMounted, watch } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { initOffline, syncState } from '@/offline/sync';
import { index as syncIndex } from '@/routes/sync';

/**
 * État réseau permanent (docs/07) : en ligne, hors connexion, synchronisation,
 * attention requise ; avec le nombre de ventes en attente.
 */
const page = usePage();
const { context, can } = useAppContext();
const enabled = computed(() => Boolean(context.value) && can('sale.create'));

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
watch(() => [context.value?.tenant.id, context.value?.warehouse?.id], start);

const state = computed(() => {
    if (syncState.attention > 0 || syncState.problem) {
        return {
            label: t('offline.attention'),
            icon: TriangleAlert,
            class: 'border-warning-border bg-warning-soft text-warning-soft-foreground',
        };
    }

    if (!syncState.online) {
        return {
            label: t('offline.offline'),
            icon: CloudOff,
            class: 'border-slate-400 bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-50',
        };
    }

    if (syncState.syncing || syncState.pending > 0) {
        return {
            label: t('offline.syncing'),
            icon: RefreshCw,
            class: 'border-info-border bg-info-soft text-info-soft-foreground',
        };
    }

    return {
        label: t('offline.online'),
        icon: Wifi,
        class: 'border-success-border bg-success-soft text-success-soft-foreground',
    };
});
</script>

<template>
    <Link
        v-if="enabled"
        :href="syncIndex()"
        class="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium"
        :class="state.class"
        role="status"
        :aria-label="`${state.label}${syncState.pending ? ' · ' + t('offline.pending', { count: syncState.pending }) : ''}`"
    >
        <component
            :is="state.icon"
            class="size-4"
            :class="syncState.syncing ? 'animate-spin' : ''"
            aria-hidden="true"
        />
        <span class="hidden sm:inline">{{ state.label }}</span>
        <span
            v-if="syncState.pending + syncState.attention > 0"
            class="rounded-full bg-background/80 px-1.5 tabular-nums"
            >{{ syncState.pending + syncState.attention }}</span
        >
    </Link>
</template>
