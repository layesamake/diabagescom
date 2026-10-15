<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Download,
    Info,
    PackageSearch,
    ReceiptText,
    RefreshCw,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { edit as productEdit } from '@/routes/products';
import { index as supplierInvoicesIndex } from '@/routes/purchases/invoices';
import { index as reportsIndex } from '@/routes/reports';
import { index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';

type MetricKey = 'total' | 'excl_tax' | 'sales' | 'average' | 'margin';
type Comparison = {
    value: number | null;
    change: number | null;
    change_basis_points: number | null;
};
type ProductRow = {
    id: string;
    sku: string;
    label: string;
    unit: string;
    type: string;
    quantity: string;
    revenue: number;
    revenue_excl_tax: number;
    cost: number | null;
    margin: number | null;
    margin_rate_basis_points: number | null;
    cost_complete: boolean | null;
    stock: string | null;
    last_sale: string | null;
};
type Priority = {
    type:
        | 'receivables'
        | 'payables_overdue'
        | 'dormant_stock'
        | 'negative_margin'
        | 'missing_cost';
    level: 'urgent' | 'important' | 'watch';
    amount: number;
    count: number;
    message: string;
    action: string;
};
type DormantItem = {
    id: string;
    sku: string;
    label: string;
    unit: string;
    quantity: string;
    last_sale: string | null;
    value: number | null;
};
type StockInsights = {
    dormant: {
        days: number;
        count: number;
        value: number | null;
        items: DormantItem[];
    };
};
type ScheduleBucket = { amount: number; count: number };
type PayablesSchedule = {
    total: number;
    overdue: ScheduleBucket;
    due_7: ScheduleBucket;
    due_8_30: ScheduleBucket;
    later: ScheduleBucket;
    missing_due: ScheduleBucket;
};
type Receivables = {
    total: number;
    overdue: number;
    missing_due: number;
    aging: Record<
        '1_7' | '8_30' | '31_60' | 'over_60',
        { amount: number; count: number }
    >;
};

const props = defineProps<{
    filters: {
        from: string;
        to: string;
        warehouse: string | null;
        dormant_days: number;
    };
    period: {
        from: string;
        to: string;
        previous_from: string;
        previous_to: string;
        today: string;
        timezone: string;
    };
    warehouses: { id: string; name: string }[];
    summary: {
        total: number;
        excl_tax: number;
        sales: number;
        average: number;
        cost: number | null;
        margin: number | null;
        margin_rate_basis_points: number | null;
        cost_coverage_basis_points: number | null;
        missing_cost_revenue: number | null;
        receivables: number | null;
        payables: number | null;
        stock_value: number | null;
    };
    comparison: Record<MetricKey, Comparison>;
    byDay: { day: string; total: number; sales: number }[];
    byDayMargin: { day: string; margin: number }[];
    byWarehouse: { label: string; total: number }[];
    bySeller: { label: string; total: number }[];
    byMethod: { method: string; total: number }[];
    topProducts: {
        sku: string;
        label: string;
        quantity: string;
        total: number;
    }[];
    products: ProductRow[];
    receivables: Receivables | null;
    payablesSchedule: PayablesSchedule | null;
    stockInsights: StockInsights | null;
    priorities: Priority[];
    freshness: string;
    can: {
        all: boolean;
        margin: boolean;
        inventory: boolean;
        purchases: boolean;
        catalog: boolean;
        sales: boolean;
        export: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('reports.title'), href: reportsIndex() }],
    },
});

const loading = ref(false);
const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    warehouse: props.filters.warehouse ?? '',
    dormant_days: props.filters.dormant_days,
});
const exportFormats = ['csv', 'xlsx', 'pdf'] as const;
const maxRevenue = computed(() =>
    Math.max(1, ...props.byDay.map((row) => Math.abs(row.total))),
);
const maxMargin = computed(() =>
    Math.max(1, ...props.byDayMargin.map((row) => Math.abs(row.margin))),
);
const activityCards = computed(() => {
    const cards: {
        key: MetricKey;
        label: string;
        value: string;
        definition: string;
        detail?: string;
    }[] = [
        {
            key: 'total',
            label: t('reports.revenue'),
            value: formatXof(props.summary.total),
            definition: t('reports.revenue_definition'),
            detail: t('reports.net_of_credit_notes'),
        },
        {
            key: 'sales',
            label: t('reports.sales_count'),
            value: String(props.summary.sales),
            definition: t('reports.sales_definition'),
        },
        {
            key: 'average',
            label: t('reports.average_basket'),
            value: formatXof(props.summary.average),
            definition: t('reports.average_definition'),
        },
    ];

    if (props.summary.margin !== null) {
        cards.splice(1, 0, {
            key: 'margin',
            label: t('reports.margin'),
            value: formatXof(props.summary.margin),
            definition: t('reports.margin_definition'),
            detail:
                props.summary.margin_rate_basis_points === null
                    ? t('reports.rate_not_applicable')
                    : `${t('reports.margin_rate')} ${formatPercent(props.summary.margin_rate_basis_points)}`,
        });
    }

    return cards;
});
const exportUrl = (format: (typeof exportFormats)[number]) =>
    reportsIndex({
        query: {
            ...props.filters,
            warehouse: props.filters.warehouse ?? '',
            format,
        },
    }).url;

function apply(): void {
    loading.value = true;
    router.get(
        reportsIndex({ query: { ...form } }).url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => (loading.value = false),
        },
    );
}

function applyPreset(preset: 'today' | 'week' | 'month'): void {
    const today = new Date(`${props.period.today}T00:00:00Z`);
    let from = props.period.today;

    if (preset === 'week') {
        const mondayOffset = (today.getUTCDay() + 6) % 7;
        today.setUTCDate(today.getUTCDate() - mondayOffset);
        from = today.toISOString().slice(0, 10);
    } else if (preset === 'month') {
        from = `${props.period.today.slice(0, 7)}-01`;
    }

    form.from = from;
    form.to = props.period.today;
    apply();
}

function formatPercent(basisPoints: number): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(basisPoints / 100)} %`;
}

function formatQuantity(value: string, unit: string): string {
    const quantity = value.replace(/\.000$/, '').replace('.', ',');
    return `${quantity} ${unit}`;
}

function stockLabel(product: ProductRow): string {
    if (product.type === 'service') {
        return t('reports.service');
    }

    return product.stock === null
        ? '—'
        : formatQuantity(product.stock, product.unit);
}

function variation(metric: MetricKey): {
    text: string;
    direction: 'up' | 'down' | 'flat';
} {
    const item = props.comparison[metric];

    if (item.change === null) {
        return { text: t('reports.comparison_unavailable'), direction: 'flat' };
    }

    const direction =
        item.change > 0 ? 'up' : item.change < 0 ? 'down' : 'flat';
    const absolute =
        metric === 'sales'
            ? String(Math.abs(item.change))
            : formatXof(Math.abs(item.change));

    if (item.change_basis_points === null) {
        return {
            text:
                item.change === 0
                    ? t('reports.stable')
                    : `${item.change > 0 ? '+' : '−'}${absolute} · ${t('reports.previous_zero')}`,
            direction,
        };
    }

    return {
        text: `${item.change > 0 ? '+' : item.change < 0 ? '−' : ''}${absolute} · ${item.change_basis_points > 0 ? '+' : ''}${formatPercent(item.change_basis_points)}`,
        direction,
    };
}

function priorityHref(priority: Priority): string {
    if (priority.type === 'receivables') {
        return salesIndex({ query: { type: 'invoice', status: 'unpaid' } }).url;
    }

    if (priority.type === 'dormant_stock') {
        return stockIndex().url;
    }

    if (priority.type === 'payables_overdue') {
        return supplierInvoicesIndex({ query: { status: 'unpaid' } }).url;
    }

    return `${reportsIndex({ query: props.filters }).url}#products`;
}
</script>

<template>
    <Head :title="t('reports.title')" />

    <main class="space-y-6 p-4 md:p-6" :aria-busy="loading">
        <header class="space-y-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ t('reports.title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            can.all
                                ? t('reports.description_all')
                                : t('reports.description_own')
                        }}
                    </p>
                </div>
                <div class="text-right text-xs text-muted-foreground">
                    <p>
                        {{ t('reports.updated_at') }}
                        {{ new Date(freshness).toLocaleString('fr-FR') }}
                    </p>
                    <p>
                        {{ t('reports.business_timezone') }}
                        {{ period.timezone }}
                    </p>
                </div>
            </div>
        </header>

        <section
            class="rounded-xl border bg-card p-4 shadow-sm"
            aria-labelledby="pilotage-filters"
        >
            <h2 id="pilotage-filters" class="sr-only">
                {{ t('reports.filters') }}
            </h2>
            <div class="mb-3 flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="applyPreset('today')"
                >
                    {{ t('reports.today') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="applyPreset('week')"
                >
                    {{ t('reports.this_week') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="applyPreset('month')"
                >
                    {{ t('reports.this_month') }}
                </Button>
            </div>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="apply"
            >
                <label class="grid gap-1 text-sm">
                    <span>{{ t('reports.from') }}</span>
                    <Input v-model="form.from" type="date" class="h-11 w-40" />
                </label>
                <label class="grid gap-1 text-sm">
                    <span>{{ t('reports.to') }}</span>
                    <Input v-model="form.to" type="date" class="h-11 w-40" />
                </label>
                <label class="grid gap-1 text-sm">
                    <span>{{ t('reports.warehouse') }}</span>
                    <select
                        v-model="form.warehouse"
                        class="h-11 min-w-48 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">
                            {{ t('reports.all_warehouses') }}
                        </option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }}
                        </option>
                    </select>
                </label>
                <Button type="submit" class="h-11" :disabled="loading">
                    <RefreshCw
                        class="size-4"
                        :class="{ 'animate-spin': loading }"
                    />
                    {{ t('reports.apply') }}
                </Button>
                <div v-if="can.export" class="flex flex-wrap gap-1 sm:ml-auto">
                    <Button
                        v-for="format in exportFormats"
                        :key="format"
                        as-child
                        variant="ghost"
                        class="h-11"
                    >
                        <a :href="exportUrl(format)">
                            <Download class="size-4" />{{
                                format.toUpperCase()
                            }}
                        </a>
                    </Button>
                </div>
            </form>
            <p class="mt-3 text-sm text-muted-foreground">
                {{ formatDate(period.from) }} – {{ formatDate(period.to) }} ·
                {{ t('reports.compared_with') }}
                {{ formatDate(period.previous_from) }} –
                {{ formatDate(period.previous_to) }}
            </p>
        </section>

        <section class="space-y-3" aria-labelledby="activity-title">
            <div>
                <h2 id="activity-title" class="text-lg font-semibold">
                    {{ t('reports.activity_period') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.activity_period_hint') }}
                </p>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="card in activityCards"
                    :key="card.key"
                    class="rounded-xl border bg-card p-4 shadow-sm"
                >
                    <dt
                        class="flex items-start justify-between gap-2 text-sm text-muted-foreground"
                    >
                        <span>{{ card.label }}</span>
                        <span class="group relative" tabindex="0">
                            <Info class="size-4" aria-hidden="true" />
                            <span class="sr-only">{{ card.definition }}</span>
                            <span
                                class="pointer-events-none absolute top-6 right-0 z-10 hidden w-64 rounded-md border bg-popover p-2 text-xs text-popover-foreground shadow-lg group-hover:block group-focus:block"
                            >
                                {{ card.definition }}
                            </span>
                        </span>
                    </dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums">
                        {{ card.value }}
                    </dd>
                    <p
                        v-if="card.detail"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ card.detail }}
                    </p>
                    <p
                        class="mt-3 flex items-center gap-1 text-xs"
                        :class="{
                            'text-emerald-700 dark:text-emerald-400':
                                variation(card.key).direction === 'up',
                            'text-red-700 dark:text-red-400':
                                variation(card.key).direction === 'down',
                            'text-muted-foreground':
                                variation(card.key).direction === 'flat',
                        }"
                    >
                        <ArrowUpRight
                            v-if="variation(card.key).direction === 'up'"
                            class="size-4"
                        />
                        <ArrowDownRight
                            v-else-if="variation(card.key).direction === 'down'"
                            class="size-4"
                        />
                        <span>{{ variation(card.key).text }}</span>
                    </p>
                </div>
            </dl>
            <p
                v-if="
                    summary.cost_coverage_basis_points !== null &&
                    summary.cost_coverage_basis_points < 10000
                "
                class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span
                    >{{ t('reports.partial_margin') }}
                    {{
                        formatPercent(summary.cost_coverage_basis_points)
                    }}.</span
                >
            </p>
        </section>

        <section
            v-if="
                receivables ||
                summary.stock_value !== null ||
                summary.payables !== null
            "
            class="space-y-3"
            aria-labelledby="situation-title"
        >
            <div>
                <h2 id="situation-title" class="text-lg font-semibold">
                    {{ t('reports.current_situation') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.current_situation_hint') }}
                </p>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-if="receivables" class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ t('reports.receivables') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(receivables.total) }}
                    </dd>
                </div>
                <div v-if="receivables" class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ t('reports.overdue_receivables') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(receivables.overdue) }}
                    </dd>
                </div>
                <div
                    v-if="summary.payables !== null"
                    class="rounded-xl border bg-card p-4"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ t('reports.payables') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(summary.payables) }}
                    </dd>
                </div>
                <div
                    v-if="summary.stock_value !== null"
                    class="rounded-xl border bg-card p-4"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ t('reports.stock_value') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(summary.stock_value) }}
                    </dd>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('reports.stock_current') }}
                    </p>
                </div>
            </dl>
        </section>

        <section class="space-y-3" aria-labelledby="priorities-title">
            <div>
                <h2 id="priorities-title" class="text-lg font-semibold">
                    {{ t('reports.priorities') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.priorities_hint') }}
                </p>
            </div>
            <EmptyState
                v-if="priorities.length === 0"
                :title="t('reports.no_priority')"
                :description="t('reports.no_priority_hint')"
            />
            <div v-else class="grid gap-3 lg:grid-cols-2">
                <article
                    v-for="priority in priorities"
                    :key="priority.type"
                    class="rounded-xl border bg-card p-4 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="rounded-full px-2 py-1 text-xs font-medium"
                            :class="
                                priority.level === 'urgent'
                                    ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'
                            "
                        >
                            {{
                                priority.level === 'urgent'
                                    ? t('reports.urgent')
                                    : priority.level === 'watch'
                                      ? t('reports.watch')
                                      : t('reports.important')
                            }}
                        </span>
                        <span class="text-xs text-muted-foreground">{{
                            new Date(freshness).toLocaleDateString('fr-FR')
                        }}</span>
                    </div>
                    <p class="mt-3 font-medium">{{ priority.message }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ formatXof(priority.amount) }} · {{ priority.count }}
                        {{ t('reports.elements') }}
                    </p>
                    <Button
                        v-if="
                            (priority.type !== 'receivables' || can.sales) &&
                            (priority.type !== 'payables_overdue' ||
                                can.purchases) &&
                            (priority.type !== 'dormant_stock' || can.inventory)
                        "
                        as-child
                        variant="link"
                        class="mt-2 h-auto p-0"
                    >
                        <Link :href="priorityHref(priority)"
                            >{{ priority.action }} <ArrowRight class="size-4"
                        /></Link>
                    </Button>
                </article>
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="evolution-title">
            <div>
                <h2 id="evolution-title" class="text-lg font-semibold">
                    {{ t('reports.understand_evolution') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.understand_evolution_hint') }}
                </p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-xl border bg-card p-4">
                    <h3 class="font-semibold">
                        {{ t('reports.revenue_evolution') }}
                    </h3>
                    <EmptyState
                        v-if="byDay.length === 0"
                        :title="t('reports.no_sales')"
                    />
                    <ul v-else class="mt-4 space-y-2">
                        <li
                            v-for="day in byDay"
                            :key="day.day"
                            class="grid grid-cols-[5.5rem_1fr_auto] items-center gap-2 text-sm"
                        >
                            <span>{{
                                new Date(
                                    `${day.day}T00:00:00Z`,
                                ).toLocaleDateString('fr-FR', {
                                    day: '2-digit',
                                    month: 'short',
                                })
                            }}</span>
                            <span
                                class="h-3 overflow-hidden rounded bg-muted"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded"
                                    :class="
                                        day.total < 0
                                            ? 'bg-red-500'
                                            : 'bg-[var(--color-brand-teal)]'
                                    "
                                    :style="{
                                        width: `${Math.max(2, (Math.abs(day.total) / maxRevenue) * 100)}%`,
                                    }"
                                />
                            </span>
                            <span class="text-right tabular-nums">{{
                                formatXof(day.total)
                            }}</span>
                        </li>
                    </ul>
                    <details v-if="byDay.length" class="mt-4 text-sm">
                        <summary class="cursor-pointer font-medium">
                            {{ t('reports.table_view') }}
                        </summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <caption class="sr-only">
                                    {{
                                        t('reports.revenue_evolution')
                                    }}
                                </caption>
                                <thead>
                                    <tr class="border-b">
                                        <th class="p-2">
                                            {{ t('reports.date') }}
                                        </th>
                                        <th class="p-2 text-right">
                                            {{ t('reports.sales_count') }}
                                        </th>
                                        <th class="p-2 text-right">
                                            {{ t('reports.revenue') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="day in byDay"
                                        :key="`table-${day.day}`"
                                        class="border-b"
                                    >
                                        <th scope="row" class="p-2 font-normal">
                                            {{ formatDate(day.day) }}
                                        </th>
                                        <td class="p-2 text-right tabular-nums">
                                            {{ day.sales }}
                                        </td>
                                        <td class="p-2 text-right tabular-nums">
                                            {{ formatXof(day.total) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article
                    v-if="can.margin"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="font-semibold">
                        {{ t('reports.margin_evolution') }}
                    </h3>
                    <EmptyState
                        v-if="byDayMargin.length === 0"
                        :title="t('reports.no_margin_data')"
                    />
                    <ul v-else class="mt-4 space-y-2">
                        <li
                            v-for="day in byDayMargin"
                            :key="day.day"
                            class="grid grid-cols-[5.5rem_1fr_auto] items-center gap-2 text-sm"
                        >
                            <span>{{
                                new Date(
                                    `${day.day}T00:00:00Z`,
                                ).toLocaleDateString('fr-FR', {
                                    day: '2-digit',
                                    month: 'short',
                                })
                            }}</span>
                            <span
                                class="h-3 overflow-hidden rounded bg-muted"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded"
                                    :class="
                                        day.margin < 0
                                            ? 'bg-red-500'
                                            : 'bg-emerald-500'
                                    "
                                    :style="{
                                        width: `${Math.max(2, (Math.abs(day.margin) / maxMargin) * 100)}%`,
                                    }"
                                />
                            </span>
                            <span class="text-right tabular-nums">{{
                                formatXof(day.margin)
                            }}</span>
                        </li>
                    </ul>
                    <details v-if="byDayMargin.length" class="mt-4 text-sm">
                        <summary class="cursor-pointer font-medium">
                            {{ t('reports.table_view') }}
                        </summary>
                        <table class="mt-2 w-full text-left text-sm">
                            <caption class="sr-only">
                                {{
                                    t('reports.margin_evolution')
                                }}
                            </caption>
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2">{{ t('reports.date') }}</th>
                                    <th class="p-2 text-right">
                                        {{ t('reports.margin') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="day in byDayMargin"
                                    :key="`margin-${day.day}`"
                                    class="border-b"
                                >
                                    <th scope="row" class="p-2 font-normal">
                                        {{ formatDate(day.day) }}
                                    </th>
                                    <td class="p-2 text-right tabular-nums">
                                        {{ formatXof(day.margin) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </details>
                </article>
            </div>
        </section>

        <section
            id="products"
            class="scroll-mt-24 space-y-3"
            aria-labelledby="products-title"
        >
            <div>
                <h2 id="products-title" class="text-lg font-semibold">
                    {{ t('reports.product_profitability') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.product_profitability_hint') }}
                </p>
            </div>
            <EmptyState
                v-if="products.length === 0"
                :title="t('reports.no_product_sales')"
                :description="t('reports.no_product_sales_hint')"
            />
            <div v-else>
                <div class="grid gap-3 md:hidden">
                    <article
                        v-for="product in products"
                        :key="product.id"
                        class="rounded-xl border bg-card p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">{{ product.label }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ product.sku }}
                                </p>
                            </div>
                            <Button
                                v-if="can.catalog"
                                as-child
                                variant="ghost"
                                size="sm"
                                ><Link :href="productEdit(product.id)"
                                    ><ArrowRight class="size-4" /><span
                                        class="sr-only"
                                        >{{ t('reports.view_product') }}</span
                                    ></Link
                                ></Button
                            >
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-muted-foreground">
                                    {{ t('reports.quantity') }}
                                </dt>
                                <dd>
                                    {{
                                        formatQuantity(
                                            product.quantity,
                                            product.unit,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    {{ t('reports.revenue_excl_tax') }}
                                </dt>
                                <dd class="tabular-nums">
                                    {{ formatXof(product.revenue_excl_tax) }}
                                </dd>
                            </div>
                            <div v-if="can.margin">
                                <dt class="text-muted-foreground">
                                    {{ t('reports.margin') }}
                                </dt>
                                <dd
                                    class="tabular-nums"
                                    :class="
                                        product.margin !== null &&
                                        product.margin < 0
                                            ? 'text-red-700 dark:text-red-400'
                                            : ''
                                    "
                                >
                                    {{
                                        product.margin === null
                                            ? '—'
                                            : formatXof(product.margin)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    {{ t('reports.stock') }}
                                </dt>
                                <dd>
                                    {{ stockLabel(product) }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="product.cost_complete === false"
                            class="mt-2 text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ t('reports.cost_missing') }}
                        </p>
                    </article>
                </div>
                <div class="hidden overflow-x-auto rounded-xl border md:block">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <caption class="sr-only">
                            {{
                                t('reports.product_profitability')
                            }}
                        </caption>
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="p-3">{{ t('reports.product') }}</th>
                                <th class="p-3 text-right">
                                    {{ t('reports.quantity') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ t('reports.revenue_excl_tax') }}
                                </th>
                                <th v-if="can.margin" class="p-3 text-right">
                                    {{ t('reports.margin') }}
                                </th>
                                <th v-if="can.margin" class="p-3 text-right">
                                    {{ t('reports.margin_rate') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ t('reports.stock') }}
                                </th>
                                <th class="p-3">
                                    {{ t('reports.last_sale') }}
                                </th>
                                <th class="p-3">
                                    <span class="sr-only">{{
                                        t('reports.actions')
                                    }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="product in products"
                                :key="product.id"
                                class="border-t"
                            >
                                <th scope="row" class="p-3">
                                    <p class="font-medium">
                                        {{ product.label }}
                                    </p>
                                    <p
                                        class="text-xs font-normal text-muted-foreground"
                                    >
                                        {{ product.sku
                                        }}<span
                                            v-if="
                                                product.cost_complete === false
                                            "
                                            class="text-amber-700 dark:text-amber-300"
                                        >
                                            ·
                                            {{
                                                t('reports.cost_missing')
                                            }}</span
                                        >
                                    </p>
                                </th>
                                <td class="p-3 text-right tabular-nums">
                                    {{
                                        formatQuantity(
                                            product.quantity,
                                            product.unit,
                                        )
                                    }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ formatXof(product.revenue_excl_tax) }}
                                </td>
                                <td
                                    v-if="can.margin"
                                    class="p-3 text-right tabular-nums"
                                    :class="
                                        product.margin !== null &&
                                        product.margin < 0
                                            ? 'text-red-700 dark:text-red-400'
                                            : ''
                                    "
                                >
                                    {{
                                        product.margin === null
                                            ? '—'
                                            : formatXof(product.margin)
                                    }}
                                </td>
                                <td
                                    v-if="can.margin"
                                    class="p-3 text-right tabular-nums"
                                >
                                    {{
                                        product.margin_rate_basis_points ===
                                        null
                                            ? '—'
                                            : formatPercent(
                                                  product.margin_rate_basis_points,
                                              )
                                    }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ stockLabel(product) }}
                                </td>
                                <td class="p-3">
                                    {{
                                        product.last_sale
                                            ? formatDate(product.last_sale)
                                            : '—'
                                    }}
                                </td>
                                <td class="p-3">
                                    <Button
                                        v-if="can.catalog"
                                        as-child
                                        variant="ghost"
                                        size="sm"
                                        ><Link :href="productEdit(product.id)"
                                            ><ArrowRight class="size-4" /><span
                                                class="sr-only"
                                                >{{
                                                    t('reports.view_product')
                                                }}</span
                                            ></Link
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section
            v-if="receivables"
            class="space-y-3"
            aria-labelledby="receivables-title"
        >
            <div>
                <h2 id="receivables-title" class="text-lg font-semibold">
                    {{ t('reports.money_to_collect') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.money_to_collect_hint') }}
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="bucket in [
                        ['1_7', t('reports.aging_1_7')],
                        ['8_30', t('reports.aging_8_30')],
                        ['31_60', t('reports.aging_31_60')],
                        ['over_60', t('reports.aging_over_60')],
                    ] as const"
                    :key="bucket[0]"
                    class="rounded-xl border bg-card p-4"
                >
                    <p class="text-sm text-muted-foreground">{{ bucket[1] }}</p>
                    <p class="text-xl font-bold tabular-nums">
                        {{ formatXof(receivables.aging[bucket[0]].amount) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ receivables.aging[bucket[0]].count }}
                        {{ t('reports.invoices') }}
                    </p>
                </article>
            </div>
            <p
                v-if="receivables.missing_due > 0"
                class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span
                    >{{ formatXof(receivables.missing_due) }}
                    {{ t('reports.missing_due_dates') }}</span
                >
            </p>
            <Button v-if="can.sales" as-child variant="outline"
                ><Link
                    :href="
                        salesIndex({
                            query: { type: 'invoice', status: 'unpaid' },
                        })
                    "
                    ><ReceiptText class="size-4" />{{
                        t('reports.view_invoices')
                    }}</Link
                ></Button
            >
        </section>

        <section
            v-if="payablesSchedule"
            class="space-y-3"
            aria-labelledby="payables-title"
        >
            <div>
                <h2 id="payables-title" class="text-lg font-semibold">
                    {{ t('reports.money_to_pay') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('reports.money_to_pay_hint') }}
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="bucket in [
                        ['overdue', t('reports.due_overdue')],
                        ['due_7', t('reports.due_7')],
                        ['due_8_30', t('reports.due_8_30')],
                        ['later', t('reports.due_later')],
                    ] as const"
                    :key="bucket[0]"
                    class="rounded-xl border bg-card p-4"
                >
                    <p class="text-sm text-muted-foreground">{{ bucket[1] }}</p>
                    <p class="text-xl font-bold tabular-nums">
                        {{ formatXof(payablesSchedule[bucket[0]].amount) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ payablesSchedule[bucket[0]].count }}
                        {{ t('reports.invoices') }}
                    </p>
                </article>
            </div>
            <p
                v-if="payablesSchedule.missing_due.amount > 0"
                class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span
                    >{{ formatXof(payablesSchedule.missing_due.amount) }}
                    {{ t('reports.missing_payable_due_dates') }}</span
                >
            </p>
            <Button v-if="can.purchases" as-child variant="outline"
                ><Link
                    :href="
                        supplierInvoicesIndex({ query: { status: 'unpaid' } })
                    "
                    ><ReceiptText class="size-4" />{{
                        t('reports.view_supplier_invoices')
                    }}</Link
                ></Button
            >
        </section>

        <section
            v-if="stockInsights"
            class="space-y-3"
            aria-labelledby="dormant-title"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="dormant-title" class="text-lg font-semibold">
                        {{ t('reports.dormant_stock') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t('reports.dormant_stock_hint', {
                                days: stockInsights.dormant.days,
                            })
                        }}
                    </p>
                </div>
                <label class="grid gap-1 text-sm">
                    <span>{{ t('reports.dormant_days') }}</span>
                    <select
                        v-model.number="form.dormant_days"
                        class="h-11 rounded-md border border-input bg-background px-3 text-sm"
                        @change="apply"
                    >
                        <option
                            v-for="days in [30, 60, 90, 180, 365]"
                            :key="days"
                            :value="days"
                        >
                            {{ days }} {{ t('reports.days') }}
                        </option>
                    </select>
                </label>
            </div>
            <EmptyState
                v-if="stockInsights.dormant.count === 0"
                :hint="t('reports.dormant_none_hint')"
            >
                {{ t('reports.dormant_none') }}
            </EmptyState>
            <template v-else>
                <p class="text-sm">
                    <strong>{{ stockInsights.dormant.count }}</strong>
                    {{ t('reports.dormant_articles') }}
                    <template v-if="stockInsights.dormant.value !== null">
                        ·
                        {{ t('reports.dormant_value') }}
                        <strong class="tabular-nums">{{
                            formatXof(stockInsights.dormant.value)
                        }}</strong>
                    </template>
                </p>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('reports.dormant_stock')
                            }}
                        </caption>
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th scope="col" class="p-3">
                                    {{ t('reports.product') }}
                                </th>
                                <th scope="col" class="p-3 text-right">
                                    {{ t('reports.quantity') }}
                                </th>
                                <th scope="col" class="p-3">
                                    {{ t('reports.last_sale') }}
                                </th>
                                <th
                                    v-if="can.margin"
                                    scope="col"
                                    class="p-3 text-right"
                                >
                                    {{ t('reports.dormant_item_value') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in stockInsights.dormant.items"
                                :key="item.id"
                                class="border-t"
                            >
                                <td class="p-3">
                                    <span class="font-medium">{{
                                        item.label
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ item.sku }}</span
                                    >
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ item.quantity }} {{ item.unit }}
                                </td>
                                <td class="p-3">
                                    {{
                                        item.last_sale
                                            ? formatDate(item.last_sale)
                                            : t('reports.never_sold')
                                    }}
                                </td>
                                <td
                                    v-if="can.margin"
                                    class="p-3 text-right tabular-nums"
                                >
                                    {{
                                        item.value !== null
                                            ? formatXof(item.value)
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="
                        stockInsights.dormant.count >
                        stockInsights.dormant.items.length
                    "
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t('reports.dormant_truncated', {
                            shown: stockInsights.dormant.items.length,
                        })
                    }}
                </p>
            </template>
            <Button v-if="can.inventory" as-child variant="outline"
                ><Link :href="stockIndex()"
                    ><PackageSearch class="size-4" />{{
                        t('reports.view_stock')
                    }}</Link
                ></Button
            >
        </section>

        <section
            class="grid gap-4 lg:grid-cols-3"
            aria-label="Détails complémentaires"
        >
            <article class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">{{ t('reports.by_method') }}</h2>
                <EmptyState
                    v-if="byMethod.length === 0"
                    :title="t('reports.no_payment')"
                />
                <ul v-else class="mt-3 divide-y text-sm">
                    <li
                        v-for="method in byMethod"
                        :key="method.method"
                        class="flex justify-between gap-3 py-2"
                    >
                        <span>{{
                            t(`methods.${method.method}` as TranslationKey)
                        }}</span
                        ><span class="tabular-nums">{{
                            formatXof(method.total)
                        }}</span>
                    </li>
                </ul>
            </article>
            <article class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">{{ t('reports.by_warehouse') }}</h2>
                <EmptyState
                    v-if="byWarehouse.length === 0"
                    :title="t('reports.no_sales')"
                />
                <ul v-else class="mt-3 divide-y text-sm">
                    <li
                        v-for="warehouse in byWarehouse"
                        :key="warehouse.label"
                        class="flex justify-between gap-3 py-2"
                    >
                        <span>{{ warehouse.label }}</span
                        ><span class="tabular-nums">{{
                            formatXof(warehouse.total)
                        }}</span>
                    </li>
                </ul>
            </article>
            <article v-if="can.all" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">{{ t('reports.by_seller') }}</h2>
                <EmptyState
                    v-if="bySeller.length === 0"
                    :title="t('reports.no_sales')"
                />
                <ul v-else class="mt-3 divide-y text-sm">
                    <li
                        v-for="seller in bySeller"
                        :key="seller.label"
                        class="flex justify-between gap-3 py-2"
                    >
                        <span>{{ seller.label }}</span
                        ><span class="tabular-nums">{{
                            formatXof(seller.total)
                        }}</span>
                    </li>
                </ul>
            </article>
        </section>

        <p
            class="flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground"
        >
            <PackageSearch class="mt-0.5 size-4 shrink-0" />
            <span>{{ t('reports.estimates_disclaimer') }}</span>
        </p>
    </main>
</template>
