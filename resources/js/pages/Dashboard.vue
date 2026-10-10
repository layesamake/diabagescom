<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    FilePlus2,
    FileWarning,
    ShoppingBasket,
    ShoppingCart,
    TriangleAlert,
    Truck,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { dashboard } from '@/routes';
import { index as cashIndex } from '@/routes/cash';
import { create as posCreate } from '@/routes/pos';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { create as salesCreate, index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';
import { index as transfersIndex } from '@/routes/transfers';

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

const props = defineProps<{
    alerts: {
        low_stock?: number;
        transfers_to_receive?: number;
        overdue_invoices?: number;
        cash_to_close?: number;
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
const hasAlert = computed(() =>
    Object.values(props.alerts).some((count) => (count ?? 0) > 0),
);
const tile =
    'flex min-h-20 flex-col items-center justify-center gap-2 rounded-lg border p-4 text-center font-medium hover:bg-accent';
</script>

<template>
    <Head :title="t('dashboard.page_title')" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <section class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{
                    t('dashboard.welcome', { name: page.props.auth.user.name })
                }}
            </h1>
            <p v-if="context" class="text-muted-foreground">
                {{ t('dashboard.subtitle', { company: context.tenant.name }) }}
                {{ t('dashboard.role', { role: context.role.name }) }}
            </p>
        </section>

        <!-- Actions fréquentes : une seule interaction depuis l'accueil -->
        <nav
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
            :aria-label="t('dashboard.shortcuts')"
        >
            <Link
                v-if="shortcuts.sell"
                :href="posCreate()"
                :class="[
                    tile,
                    'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                ]"
            >
                <ShoppingCart class="size-6" aria-hidden="true" />{{
                    t('sales.pos')
                }}
            </Link>
            <Link
                v-if="shortcuts.quote"
                :href="salesCreate('quote')"
                :class="tile"
            >
                <FilePlus2 class="size-6" aria-hidden="true" />{{
                    t('sales.new_quote')
                }}
            </Link>
            <Link v-if="shortcuts.cash" :href="cashIndex()" :class="tile">
                <Wallet class="size-6" aria-hidden="true" />{{ t('cash.nav') }}
            </Link>
            <Link
                v-if="shortcuts.purchase"
                :href="purchaseOrdersIndex()"
                :class="tile"
            >
                <ShoppingBasket class="size-6" aria-hidden="true" />{{
                    t('purchases.nav')
                }}
            </Link>
        </nav>

        <section
            v-if="hasAlertSection"
            class="space-y-2"
            aria-labelledby="alerts-title"
        >
            <h2 id="alerts-title" class="font-semibold">
                {{ t('dashboard.alerts_title') }}
            </h2>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li v-if="alerts.low_stock">
                    <Link
                        :href="stockIndex({ query: { low: 1 } })"
                        class="flex min-h-16 items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
                    >
                        <TriangleAlert
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        {{
                            t('dashboard.low_stock', {
                                count: alerts.low_stock,
                            })
                        }}
                    </Link>
                </li>
                <li v-if="alerts.overdue_invoices">
                    <Link
                        :href="
                            salesIndex({
                                query: { type: 'invoice', status: 'unpaid' },
                            })
                        "
                        class="flex min-h-16 items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
                    >
                        <FileWarning
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        {{
                            t('dashboard.overdue_invoices', {
                                count: alerts.overdue_invoices,
                            })
                        }}
                    </Link>
                </li>
                <li v-if="alerts.cash_to_close">
                    <Link
                        :href="cashIndex()"
                        class="flex min-h-16 items-center gap-3 rounded-lg border p-4 hover:bg-accent"
                    >
                        <Wallet class="size-5 shrink-0" aria-hidden="true" />
                        {{
                            t('dashboard.cash_to_close', {
                                count: alerts.cash_to_close,
                            })
                        }}
                    </Link>
                </li>
                <li v-if="alerts.transfers_to_receive">
                    <Link
                        :href="transfersIndex()"
                        class="flex min-h-16 items-center gap-3 rounded-lg border p-4 hover:bg-accent"
                    >
                        <Truck class="size-5 shrink-0" aria-hidden="true" />
                        {{
                            t('dashboard.transfers_to_receive', {
                                count: alerts.transfers_to_receive,
                            })
                        }}
                    </Link>
                </li>
                <li v-if="!hasAlert" class="text-sm text-muted-foreground">
                    {{ t('dashboard.no_alerts') }}
                </li>
            </ul>
        </section>
    </div>
</template>
