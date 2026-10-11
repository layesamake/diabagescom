<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { auditActionLabel, t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { index as tenantsIndex } from '@/routes/platform/tenants';
import { revoke as revokeRoute } from '@/routes/platform/support';
import type { SubscriptionSummary } from '@/types';

const props = defineProps<{
    tenant: { id: string; name: string; legal_name: string; status: string };
    grant: { reason: string; expires_at: string };
    subscription: SubscriptionSummary;
    members: { name: string; email: string; role: string; status: string }[];
    warehouses: { code: string; name: string; status: string }[];
    audit: {
        action: string;
        actor: string | null;
        created_at: string | null;
    }[];
    sync_issues: {
        status: string;
        error_code: string | null;
        error_message: string | null;
        created_at: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('platform.tenants_title'), href: tenantsIndex() },
        ],
    },
});

const time = new Intl.DateTimeFormat('fr-FR', { timeStyle: 'short' });
</script>

<template>
    <Head :title="t('billing.support_title', { name: tenant.name })" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="t('billing.support_title', { name: tenant.name })"
                :description="tenant.legal_name"
            />
            <Button
                variant="destructive"
                class="h-11"
                @click="router.post(revokeRoute(props.tenant.id).url)"
                >{{ t('billing.support_revoke') }}</Button
            >
        </div>
        <p
            class="rounded-lg border border-warning-border bg-warning-soft p-3 text-sm text-warning-soft-foreground"
        >
            {{
                t('billing.support_until', {
                    time: time.format(new Date(grant.expires_at)),
                    reason: grant.reason,
                })
            }}
        </p>

        <p class="text-sm">
            {{ subscription.plan?.name ?? '—' }} ·
            {{ t(`subscription.status.${subscription.status}`) }}
        </p>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.members') }}</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="m in members"
                    :key="m.email"
                    class="flex flex-wrap gap-3 p-2"
                >
                    <span class="font-medium">{{ m.name }}</span>
                    <span class="flex-1 text-muted-foreground">{{
                        m.email
                    }}</span>
                    <span>{{ m.role }}</span>
                    <span class="text-muted-foreground">{{ m.status }}</span>
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                {{ warehouses.map((w) => `${w.name} (${w.code})`).join(' · ') }}
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.sync_issues') }}</h2>
            <p
                v-if="sync_issues.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="(issue, index) in sync_issues"
                    :key="index"
                    class="flex flex-wrap gap-3 p-2"
                >
                    <span class="font-medium">{{ issue.status }}</span>
                    <span class="flex-1">{{ issue.error_message }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(issue.created_at)
                    }}</span>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.recent_audit') }}</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="(event, index) in audit"
                    :key="index"
                    class="flex flex-wrap gap-3 p-2"
                >
                    <span class="flex-1">{{
                        auditActionLabel(event.action)
                    }}</span>
                    <span class="text-muted-foreground">{{
                        event.actor ?? '—'
                    }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(event.created_at)
                    }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
