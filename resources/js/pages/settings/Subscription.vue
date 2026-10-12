<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import BillingPanel from '@/components/BillingPanel.vue';
import Heading from '@/components/Heading.vue';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { show as subscriptionShow } from '@/routes/subscription';
import type { PlanOption, SubscriptionSummary } from '@/types';

type Limit = { usage: number; limit: number | null };

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('subscription.page_title'), href: subscriptionShow() },
        ],
    },
});

defineProps<{
    subscription: SubscriptionSummary;
    limits: { warehouses: Limit; users: Limit };
    plans: PlanOption[];
    addons: {
        code: string;
        label: string;
        price_monthly: number | null;
        price_yearly: number | null;
    }[];
    invoices: InstanceType<typeof BillingPanel>['$props']['invoices'];
    providers: string[];
    transferInstructions: string | null;
    waveNumber: string | null;
    can: { manage: boolean };
}>();

const limitLabel = (limit: number | null): string =>
    limit === null ? t('subscription.unlimited') : String(limit);
</script>

<template>
    <Head :title="t('subscription.page_title')" />

    <div class="space-y-8">
        <Heading
            variant="small"
            :title="t('subscription.heading')"
            :description="t('subscription.description')"
        />

        <dl class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ t('subscription.plan') }}
                </dt>
                <dd class="text-lg font-semibold">
                    {{ subscription.plan?.name ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ t('subscription.status_label') }}
                </dt>
                <dd
                    class="text-lg font-semibold"
                    data-test="subscription-status"
                >
                    {{ t(`subscription.status.${subscription.status}`) }}
                </dd>
                <dd
                    v-if="subscription.status === 'trialing'"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t('subscription.trial_ends', {
                            date: formatDate(subscription.trial_ends_at),
                        })
                    }}
                </dd>
                <dd
                    v-if="subscription.status === 'read_only'"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t('subscription.read_only_ends', {
                            date: formatDate(subscription.read_only_ends_at),
                        })
                    }}
                </dd>
            </div>
        </dl>

        <section class="space-y-2">
            <h3 class="font-medium">{{ t('subscription.usage_title') }}</h3>
            <ul class="space-y-1 text-sm">
                <li>
                    {{
                        t('subscription.warehouses_usage', {
                            usage: limits.warehouses.usage,
                            limit: limitLabel(limits.warehouses.limit),
                        })
                    }}
                </li>
                <li>
                    {{
                        t('subscription.users_usage', {
                            usage: limits.users.usage,
                            limit: limitLabel(limits.users.limit),
                        })
                    }}
                </li>
            </ul>
        </section>

        <BillingPanel
            v-if="can.manage"
            :plans="plans"
            :current-plan="subscription.plan?.code ?? null"
            :addons="addons"
            :invoices="invoices"
            :providers="providers"
            :transfer-instructions="transferInstructions"
            :wave-number="waveNumber"
        />
        <p
            v-else
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ t('subscription.regularize') }}
        </p>

        <section class="space-y-3">
            <h3 class="font-medium">{{ t('subscription.plans_title') }}</h3>
            <ul class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="plan in plans"
                    :key="plan.code"
                    class="rounded-lg border p-4"
                    :class="
                        plan.code === subscription.plan?.code
                            ? 'border-[var(--color-brand-teal)] ring-1 ring-[var(--color-brand-teal)]'
                            : ''
                    "
                >
                    <p class="font-semibold">{{ plan.name }}</p>
                    <p
                        v-if="plan.code === subscription.plan?.code"
                        class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-[var(--color-brand-teal)]"
                    >
                        <Check class="size-3.5" aria-hidden="true" />
                        {{ t('subscription.current') }}
                    </p>
                    <ul class="mt-2 space-y-0.5 text-sm text-muted-foreground">
                        <li>
                            {{
                                plan.warehouse_limit === null
                                    ? t('subscription.unlimited')
                                    : t('subscription.warehouses_count', {
                                          count: plan.warehouse_limit,
                                      })
                            }}
                        </li>
                        <li>
                            {{
                                t('subscription.users_count', {
                                    count: plan.included_users,
                                })
                            }}
                        </li>
                        <li>
                            {{
                                plan.accounting_enabled
                                    ? t('subscription.accounting_included')
                                    : t('subscription.accounting_excluded')
                            }}
                        </li>
                        <li class="font-medium text-foreground">
                            {{
                                plan.price_monthly == null
                                    ? t('subscription.price_pending')
                                    : t('subscription.price_monthly', {
                                          amount: formatXof(plan.price_monthly),
                                      })
                            }}
                        </li>
                    </ul>
                </li>
            </ul>
        </section>
    </div>
</template>
