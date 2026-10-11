<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CircleAlert, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/i18n';
import { show as subscriptionShow } from '@/routes/subscription';

/**
 * Bandeau d'état de l'abonnement : visible pendant l'essai, en lecture seule
 * et en suspension. Information seulement : le serveur applique les règles.
 */
const page = usePage();
const subscription = computed(() => page.props.subscription);

const message = computed(() => {
    const s = subscription.value;

    if (!s) {
        return null;
    }

    switch (s.status) {
        case 'trialing':
            return s.trial_days_left === 0
                ? t('subscription.banner.trialing_last')
                : t('subscription.banner.trialing', {
                      days: s.trial_days_left ?? 0,
                  });
        case 'read_only':
            return t('subscription.banner.read_only');
        case 'suspended':
            return t('subscription.banner.suspended');
        default:
            return null;
    }
});

const isWarning = computed(() =>
    ['read_only', 'suspended'].includes(subscription.value?.status ?? ''),
);
</script>

<template>
    <div
        v-if="message"
        role="status"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-2 text-sm"
        :class="
            isWarning
                ? 'border-warning-border bg-warning-soft text-warning-soft-foreground'
                : 'border-sidebar-border/70 bg-[var(--color-surface)] text-foreground dark:bg-muted'
        "
    >
        <component
            :is="isWarning ? CircleAlert : Clock"
            class="size-4 shrink-0"
            aria-hidden="true"
        />
        <span class="min-w-0 flex-1">{{ message }}</span>
        <Link
            :href="subscriptionShow()"
            class="inline-flex min-h-11 items-center font-medium underline underline-offset-4"
        >
            {{ t('subscription.banner.action') }}
        </Link>
    </div>
</template>
