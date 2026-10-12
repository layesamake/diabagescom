<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    CircleCheck,
    FilePlus2,
    FileWarning,
    ShoppingBasket,
    ShoppingCart,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    Truck,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as cashIndex } from '@/routes/cash';
import { create as posCreate } from '@/routes/pos';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { create as salesCreate, index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';
import { show as subscriptionShow } from '@/routes/subscription';
import { index as syncIndex } from '@/routes/sync';
import { index as transfersIndex } from '@/routes/transfers';
import StartChecklist from '@/components/StartChecklist.vue';
import type { ChecklistStep } from '@/components/StartChecklist.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t('nav.dashboard'),
                href: dashboard(),
            },
        ],
    },
});

type Day = { day: string; total: number; sales: number };

const props = defineProps<{
    figures: {
        today: Day;
        yesterday: Day;
        week: Day[];
        month: number;
        receivables: number | null;
    } | null;
    checklist: ChecklistStep[] | null;
    alerts: {
        low_stock?: number;
        transfers_to_receive?: number;
        overdue_invoices?: number;
        cash_to_close?: number;
        saas_invoice?: string | null;
        sync_issues?: number;
    };
    shortcuts: {
        sell: boolean;
        quote: boolean;
        cash: boolean;
        purchase: boolean;
    };
}>();

const page = usePage();
const { context } = useAppContext();
const hasAlertSection = computed(() => Object.keys(props.alerts).length > 0);

const greeting = computed(() =>
    t(
        new Date().getHours() < 18
            ? 'dashboard.greeting_morning'
            : 'dashboard.greeting_evening',
        { name: page.props.auth.user.name.split(' ')[0] },
    ),
);
const todayLabel = new Intl.DateTimeFormat('fr-FR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
}).format(new Date());
const weekday = new Intl.DateTimeFormat('fr-FR', { weekday: 'long' });
const dayName = (day: string) => weekday.format(new Date(`${day}T12:00:00`));

const trend = computed(() => {
    if (!props.figures) {
        return null;
    }

    const diff = props.figures.today.total - props.figures.yesterday.total;
    const amount = formatXof(Math.abs(diff));

    if (diff > 0) {
        return {
            icon: TrendingUp,
            tone: 'text-success',
            text: t('dashboard.vs_yesterday_up', { amount }),
        };
    }

    if (diff < 0) {
        return {
            icon: TrendingDown,
            tone: 'text-warning',
            text: t('dashboard.vs_yesterday_down', { amount }),
        };
    }

    return {
        icon: null,
        tone: 'text-muted-foreground',
        text: t('dashboard.vs_yesterday_same'),
    };
});
const weekMax = computed(() =>
    Math.max(1, ...(props.figures?.week.map((d) => d.total) ?? [0])),
);

// Actions fréquentes : un titre court et une phrase qui dit à quoi elles servent.
const actions = computed(() =>
    [
        props.shortcuts.sell && {
            href: posCreate(),
            icon: ShoppingCart,
            title: t('sales.pos'),
            hint: t('dashboard.action_sell'),
            main: true,
        },
        props.shortcuts.quote && {
            href: salesCreate('quote'),
            icon: FilePlus2,
            title: t('sales.new_quote'),
            hint: t('dashboard.action_quote'),
            main: false,
        },
        props.shortcuts.cash && {
            href: cashIndex(),
            icon: Wallet,
            title: t('cash.nav'),
            hint: t('dashboard.action_cash'),
            main: false,
        },
        props.shortcuts.purchase && {
            href: purchaseOrdersIndex(),
            icon: ShoppingBasket,
            title: t('purchases.nav'),
            hint: t('dashboard.action_purchase'),
            main: false,
        },
    ].filter((item) => item !== false),
);

// À traiter : chaque alerte mène directement à la liste concernée.
const alertItems = computed(() => {
    const a = props.alerts;

    return [
        a.low_stock && {
            href: stockIndex({ query: { low: 1 } }),
            icon: TriangleAlert,
            text: t('dashboard.low_stock', { count: a.low_stock }),
            warning: true,
        },
        a.overdue_invoices && {
            href: salesIndex({ query: { type: 'invoice', status: 'unpaid' } }),
            icon: FileWarning,
            text: t('dashboard.overdue_invoices', {
                count: a.overdue_invoices,
            }),
            warning: true,
        },
        a.saas_invoice && {
            href: subscriptionShow(),
            icon: FileWarning,
            text: t('dashboard.saas_invoice', { number: a.saas_invoice }),
            warning: true,
        },
        a.sync_issues && {
            href: syncIndex(),
            icon: TriangleAlert,
            text: t('dashboard.sync_issues', { count: a.sync_issues }),
            warning: true,
        },
        a.cash_to_close && {
            href: cashIndex(),
            icon: Wallet,
            text: t('dashboard.cash_to_close', { count: a.cash_to_close }),
            warning: false,
        },
        a.transfers_to_receive && {
            href: transfersIndex(),
            icon: Truck,
            text: t('dashboard.transfers_to_receive', {
                count: a.transfers_to_receive,
            }),
            warning: false,
        },
    ].filter((item) => Boolean(item)) as {
        href: ReturnType<typeof cashIndex>;
        icon: typeof Wallet;
        text: string;
        warning: boolean;
    }[];
});

const card = 'rounded-xl border bg-card p-5 text-card-foreground shadow-xs';
</script>

<template>
    <Head :title="t('dashboard.page_title')" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-4 md:p-8">
        <header class="space-y-1">
            <p class="text-sm text-muted-foreground first-letter:uppercase">
                {{ todayLabel }}
            </p>
            <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">
                {{ greeting }}
            </h1>
            <p v-if="context" class="text-muted-foreground">
                {{ t('dashboard.subtitle', { company: context.tenant.name }) }}
                {{ t('dashboard.role', { role: context.role.name }) }}
            </p>
        </header>

        <StartChecklist v-if="checklist" :steps="checklist" />

        <!-- Chiffres clés : rôles autorisés aux rapports uniquement -->
        <section
            v-if="figures"
            aria-labelledby="figures-title"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <h2 id="figures-title" class="sr-only">
                {{ t('dashboard.figures_title') }}
            </h2>
            <div :class="card">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('dashboard.today_sales') }}
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ formatXof(figures.today.total) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        t('dashboard.sales_count', {
                            count: figures.today.sales,
                        })
                    }}
                </p>
                <p
                    v-if="trend"
                    class="mt-3 flex items-center gap-1.5 text-sm"
                    :class="trend.tone"
                >
                    <component
                        :is="trend.icon"
                        v-if="trend.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ trend.text }}
                </p>
            </div>
            <div :class="card">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('dashboard.month_sales') }}
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ formatXof(figures.month) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('dashboard.month_hint') }}
                </p>
            </div>
            <div v-if="figures.receivables !== null" :class="card">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('dashboard.receivables') }}
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ formatXof(figures.receivables) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('dashboard.receivables_hint') }}
                </p>
            </div>
            <div :class="card">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('dashboard.week_title') }}
                </p>
                <ol class="mt-3 flex h-20 items-end gap-1.5">
                    <li
                        v-for="(day, index) in figures.week"
                        :key="day.day"
                        class="flex h-full flex-1 flex-col items-center justify-end gap-1"
                        :title="
                            t('dashboard.week_day', {
                                day: dayName(day.day),
                                amount: formatXof(day.total),
                            })
                        "
                    >
                        <span
                            class="w-full rounded-sm"
                            :class="
                                index === figures.week.length - 1
                                    ? 'bg-primary'
                                    : 'bg-primary/35'
                            "
                            :style="{
                                height: `${Math.max(4, (Math.max(day.total, 0) / weekMax) * 85)}%`,
                            }"
                        />
                        <span
                            class="text-[11px] text-muted-foreground"
                            aria-hidden="true"
                            >{{ dayName(day.day).slice(0, 2) }}</span
                        >
                        <span class="sr-only">{{
                            t('dashboard.week_day', {
                                day: dayName(day.day),
                                amount: formatXof(day.total),
                            })
                        }}</span>
                    </li>
                </ol>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-5">
            <!-- Actions fréquentes : une seule interaction depuis l'accueil -->
            <section v-if="actions.length" class="space-y-3 lg:col-span-3">
                <h2 class="text-lg font-semibold">
                    {{ t('dashboard.actions_title') }}
                </h2>
                <nav
                    class="grid gap-3 sm:grid-cols-2"
                    :aria-label="t('dashboard.shortcuts')"
                >
                    <Link
                        v-for="item in actions"
                        :key="item.title"
                        :href="item.href"
                        class="group flex min-h-20 items-center gap-4 rounded-xl border p-4 text-left shadow-xs transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        :class="
                            item.main
                                ? 'border-primary/50 bg-primary/10 hover:bg-primary/15'
                                : 'bg-card hover:border-primary/60 hover:bg-accent'
                        "
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                            :class="
                                item.main
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-primary/10 text-primary'
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold">{{
                                item.title
                            }}</span>
                            <span class="block text-sm text-muted-foreground">{{
                                item.hint
                            }}</span>
                        </span>
                        <ChevronRight
                            class="size-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                </nav>
            </section>

            <section
                v-if="hasAlertSection"
                class="space-y-3 lg:col-span-2"
                aria-labelledby="alerts-title"
            >
                <h2 id="alerts-title" class="text-lg font-semibold">
                    {{ t('dashboard.alerts_title') }}
                </h2>
                <ul v-if="alertItems.length" class="space-y-2">
                    <li v-for="item in alertItems" :key="item.text">
                        <Link
                            :href="item.href"
                            class="flex min-h-14 items-center gap-3 rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                            :class="
                                item.warning
                                    ? 'border-warning-border bg-warning-soft text-warning-soft-foreground hover:bg-warning-soft/80'
                                    : 'bg-card hover:bg-accent'
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-5 shrink-0"
                                :class="{ 'text-primary': !item.warning }"
                                aria-hidden="true"
                            />
                            <span class="flex-1">{{ item.text }}</span>
                            <ChevronRight
                                class="size-4 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
                <div
                    v-else
                    class="flex items-center gap-3 rounded-xl border border-dashed p-5"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success-soft text-success"
                    >
                        <CircleCheck class="size-5" aria-hidden="true" />
                    </span>
                    <span>
                        <span class="block font-medium">{{
                            t('dashboard.all_clear')
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            t('dashboard.all_clear_hint')
                        }}</span>
                    </span>
                </div>
            </section>
        </div>
    </div>
</template>
