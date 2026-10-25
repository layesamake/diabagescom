<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { dashboard as platformDashboard } from '@/routes/platform';
import { index as billingIndex } from '@/routes/platform/billing';
import { index as tenantsIndex } from '@/routes/platform/tenants';

type Month = {
    month: string;
    collected: number;
    new_tenants: number;
    conversions: number;
};

const props = defineProps<{
    metrics: {
        mrr: number;
        arr: number;
        unpriced_active: number;
        statuses: Record<
            'trialing' | 'active' | 'read_only' | 'suspended' | 'cancelled',
            number
        >;
        tenants_total: number;
        new_tenants: { this_month: number; last_month: number };
        conversions: { this_month: number; rate: number | null };
        churn_this_month: number;
        collected: { this_month: number; last_month: number };
        unpaid: {
            count: number;
            total: number;
            overdue_count: number;
            overdue_total: number;
        };
        transfers_to_review: number;
        trials_ending: {
            tenant_id: string;
            tenant: string;
            plan: string;
            trial_ends_at: string;
        }[];
        overdue_invoices: {
            id: string;
            number: string;
            tenant: string;
            total: number;
            due_on: string;
        }[];
        history: Month[];
    };
    activation: { total: number; steps: Record<string, number> };
    stalled: {
        id: string;
        name: string;
        created_at: string;
        next: string | null;
    }[];
    canManageBilling: boolean;
}>();

const milestoneKeys = [
    'company',
    'products',
    'stock',
    'cash',
    'sale',
    'customer',
    'team',
    'backup',
] as const;

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t('platform.dashboard_title'),
                href: platformDashboard(),
            },
        ],
    },
});

const monthLabel = new Intl.DateTimeFormat('fr-FR', { month: 'short' });
const shortMonth = (key: string) =>
    monthLabel.format(new Date(`${key}-15T12:00:00`));

const collectedMax = computed(() =>
    Math.max(1, ...props.metrics.history.map((m) => m.collected)),
);
const tenantsMax = computed(() =>
    Math.max(1, ...props.metrics.history.map((m) => m.new_tenants)),
);

const kpis = computed(() => {
    const m = props.metrics;

    return [
        {
            label: t('platform.kpi.mrr'),
            value: formatXof(m.mrr),
            hint:
                m.unpriced_active > 0
                    ? t('platform.kpi.unpriced', { count: m.unpriced_active })
                    : t('platform.kpi.arr', { amount: formatXof(m.arr) }),
            warn: m.unpriced_active > 0,
        },
        {
            label: t('platform.kpi.collected'),
            value: formatXof(m.collected.this_month),
            hint: t('platform.kpi.last_month', {
                value: formatXof(m.collected.last_month),
            }),
            warn: false,
        },
        {
            label: t('platform.kpi.unpaid'),
            value: formatXof(m.unpaid.total),
            hint: t('platform.kpi.overdue', {
                count: m.unpaid.overdue_count,
                amount: formatXof(m.unpaid.overdue_total),
            }),
            warn: m.unpaid.overdue_count > 0,
        },
        {
            label: t('platform.kpi.new_tenants'),
            value: String(m.new_tenants.this_month),
            hint: t('platform.kpi.last_month', {
                value: String(m.new_tenants.last_month),
            }),
            warn: false,
        },
        {
            label: t('platform.kpi.trials'),
            value: String(m.statuses.trialing),
            hint: t('platform.kpi.trials_ending', {
                count: m.trials_ending.length,
            }),
            warn: false,
        },
        {
            label: t('platform.kpi.paying'),
            value: String(m.statuses.active),
            hint: t('platform.kpi.total_tenants', { count: m.tenants_total }),
            warn: false,
        },
        {
            label: t('platform.kpi.conversions'),
            value: String(m.conversions.this_month),
            hint:
                m.conversions.rate === null
                    ? t('platform.kpi.rate_unknown')
                    : t('platform.kpi.rate', {
                          rate: String(m.conversions.rate),
                      }),
            warn: false,
        },
        {
            label: t('platform.kpi.churn'),
            value: String(m.churn_this_month),
            hint: t('platform.kpi.inactive', {
                count: m.statuses.read_only + m.statuses.suspended,
            }),
            warn: m.churn_this_month > 0,
        },
    ];
});
</script>

<template>
    <Head :title="t('platform.dashboard_title')" />

    <div class="space-y-8 p-4 md:p-6">
        <Heading
            :title="t('platform.dashboard_title')"
            :description="t('platform.dashboard_description')"
        />

        <section
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            :aria-label="t('platform.kpi.section')"
        >
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="rounded-xl border bg-card p-4"
            >
                <p class="text-sm text-muted-foreground">{{ kpi.label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums md:text-2xl">
                    {{ kpi.value }}
                </p>
                <p
                    class="mt-1 text-xs"
                    :class="kpi.warn ? 'text-warning' : 'text-muted-foreground'"
                >
                    {{ kpi.hint }}
                </p>
            </div>
        </section>

        <section
            v-if="canManageBilling && metrics.transfers_to_review > 0"
            class="flex flex-wrap items-center gap-3 rounded-xl border border-warning-border bg-warning-soft p-4 text-sm text-warning-soft-foreground"
        >
            <span class="flex-1">{{
                t('platform.transfers_to_review', {
                    count: metrics.transfers_to_review,
                })
            }}</span>
            <Link :href="billingIndex()" class="font-medium underline">{{
                t('platform.open_billing')
            }}</Link>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border p-4">
                <h2 class="font-semibold">
                    {{ t('platform.chart_collected') }}
                </h2>
                <div class="mt-4 flex h-40 items-end gap-1.5">
                    <div
                        v-for="m in metrics.history"
                        :key="m.month"
                        class="flex flex-1 flex-col items-center gap-1"
                        :title="`${shortMonth(m.month)} : ${formatXof(m.collected)}`"
                    >
                        <div
                            class="bg-brand-teal w-full rounded-t"
                            :style="{
                                height: `${Math.max(2, (m.collected / collectedMax) * 128)}px`,
                            }"
                        />
                        <span class="text-[10px] text-muted-foreground">{{
                            shortMonth(m.month)
                        }}</span>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <h2 class="font-semibold">
                    {{ t('platform.chart_tenants') }}
                </h2>
                <div class="mt-4 flex h-40 items-end gap-1.5">
                    <div
                        v-for="m in metrics.history"
                        :key="m.month"
                        class="flex flex-1 flex-col items-center gap-1"
                        :title="
                            t('platform.chart_tenants_tip', {
                                month: shortMonth(m.month),
                                created: m.new_tenants,
                                converted: m.conversions,
                            })
                        "
                    >
                        <div
                            class="bg-brand-navy dark:bg-brand-teal-light w-full rounded-t"
                            :style="{
                                height: `${Math.max(2, (m.new_tenants / tenantsMax) * 128)}px`,
                            }"
                        />
                        <span class="text-[10px] text-muted-foreground">{{
                            shortMonth(m.month)
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-2">
                <h2 class="font-semibold">
                    {{ t('platform.trials_ending_title') }}
                </h2>
                <p
                    v-if="metrics.trials_ending.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('platform.nothing_to_do') }}
                </p>
                <ul v-else class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="trial in metrics.trials_ending"
                        :key="trial.tenant_id"
                        class="flex flex-wrap items-center gap-3 p-3"
                    >
                        <Link
                            :href="tenantsIndex({ query: { q: trial.tenant } })"
                            class="flex-1 font-medium hover:underline"
                            >{{ trial.tenant }}</Link
                        >
                        <span class="text-muted-foreground">{{
                            trial.plan
                        }}</span>
                        <span>{{
                            t('platform.trial_ends_on', {
                                date: formatDate(trial.trial_ends_at),
                            })
                        }}</span>
                    </li>
                </ul>
            </div>
            <div class="space-y-2">
                <h2 class="font-semibold">
                    {{ t('platform.overdue_title') }}
                </h2>
                <p
                    v-if="metrics.overdue_invoices.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('platform.nothing_to_do') }}
                </p>
                <ul v-else class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="invoice in metrics.overdue_invoices"
                        :key="invoice.id"
                        class="flex flex-wrap items-center gap-3 p-3"
                    >
                        <span class="font-mono text-xs">{{
                            invoice.number
                        }}</span>
                        <span class="flex-1 font-medium">{{
                            invoice.tenant
                        }}</span>
                        <span class="tabular-nums">{{
                            formatXof(invoice.total)
                        }}</span>
                        <span class="text-warning">{{
                            formatDate(invoice.due_on)
                        }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="font-semibold">
                    {{ t('platform.activation_title') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('platform.activation_note') }}
                </p>
            </div>
            <ul class="space-y-2 text-sm">
                <li v-for="key in milestoneKeys" :key="key" class="space-y-1">
                    <div class="flex justify-between gap-3">
                        <span>{{ t(`platform.milestone.${key}`) }}</span>
                        <span class="text-muted-foreground tabular-nums">{{
                            t('platform.activation_of', {
                                count: activation.steps[key] ?? 0,
                                total: activation.total,
                            })
                        }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{
                                width:
                                    activation.total === 0
                                        ? '0%'
                                        : `${Math.round(((activation.steps[key] ?? 0) / activation.total) * 100)}%`,
                            }"
                        />
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('platform.stalled_title') }}</h2>
            <p
                v-if="stalled.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('platform.nothing_to_do') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="row in stalled"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <Link
                        :href="tenantsIndex({ query: { q: row.name } })"
                        class="flex-1 font-medium hover:underline"
                        >{{ row.name }}</Link
                    >
                    <span v-if="row.next" class="text-muted-foreground">{{
                        t('platform.stalled_next', {
                            step: t(
                                `platform.milestone.${row.next as (typeof milestoneKeys)[number]}`,
                            ),
                        })
                    }}</span>
                    <span class="text-muted-foreground">{{
                        t('platform.stalled_since', {
                            date: formatDate(row.created_at),
                        })
                    }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
