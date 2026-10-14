<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Pager from '@/components/Pager.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import {
    create as invoiceCreate,
    index as invoicesIndex,
    show as invoiceShow,
} from '@/routes/purchases/invoices';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type Invoice = {
    id: string;
    number: string | null;
    supplier_reference: string | null;
    status: 'draft' | 'validated' | 'cancelled';
    payment_status: 'unpaid' | 'partial' | 'paid' | 'refund_due' | null;
    supplier: { name: string };
    total: number;
    balance: number;
    due_date: string | null;
};

defineProps<{
    invoices: Paginated<Invoice>;
    debts: {
        id: string;
        code: string;
        name: string;
        due: number;
        overdue: number;
    }[];
    filters: { status: string };
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('purchases.invoices'), href: invoicesIndex() },
        ],
    },
});

const tabs = [
    { value: '', label: t('purchases.all') },
    { value: 'unpaid', label: t('purchases.unpaid') },
    { value: 'draft', label: t('purchases.status.draft') },
];

function filter(status: string): void {
    router.get(
        invoicesIndex({ query: status ? { status } : {} }).url,
        {},
        { preserveState: true },
    );
}
</script>

<template>
    <Head :title="t('purchases.invoices')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('purchases.invoices')"
                :description="t('purchases.invoices_description')"
            />
            <Button v-if="can.create" as-child class="h-11"
                ><Link :href="invoiceCreate()"
                    ><Plus class="size-4" />{{
                        t('purchases.new_invoice')
                    }}</Link
                ></Button
            >
        </div>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('purchases.debts') }}</h2>
            <p v-if="debts.length === 0" class="text-sm text-muted-foreground">
                {{ t('purchases.no_debts') }}
            </p>
            <ul v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="d in debts"
                    :key="d.id"
                    class="rounded-lg border p-3"
                >
                    <p class="font-medium">{{ d.name }}</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ formatXof(d.due) }}
                    </p>
                    <p v-if="d.overdue" class="text-sm text-destructive">
                        {{
                            t('purchases.overdue', {
                                amount: formatXof(d.overdue),
                            })
                        }}
                    </p>
                </li>
            </ul>
        </section>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="tab in tabs"
                :key="tab.value"
                role="tab"
                :aria-selected="filters.status === tab.value"
                :variant="filters.status === tab.value ? 'default' : 'outline'"
                class="h-11"
                @click="filter(tab.value)"
                >{{ tab.label }}</Button
            >
        </div>

        <EmptyState v-if="invoices.data.length === 0">
            {{ t('purchases.empty') }}
            <template v-if="can.create" #action>
                <Button as-child class="h-11"
                    ><Link :href="invoiceCreate()"
                        ><Plus class="size-4" />{{
                            t('purchases.new_invoice')
                        }}</Link
                    ></Button
                >
            </template>
        </EmptyState>
        <ul v-else class="divide-y rounded-lg border">
            <li v-for="inv in invoices.data" :key="inv.id">
                <Link
                    :href="invoiceShow(inv.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-36 font-medium"
                        >{{ inv.number ?? t('purchases.status.draft') }}
                        <span
                            v-if="inv.supplier_reference"
                            class="block text-xs font-normal text-muted-foreground"
                            >{{ inv.supplier_reference }}</span
                        ></span
                    >
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        inv.supplier.name
                    }}</span>
                    <span
                        v-if="inv.due_date"
                        class="text-sm text-muted-foreground"
                        >{{ t('purchases.due_date') }}
                        {{ formatDate(inv.due_date) }}</span
                    >
                    <span class="font-semibold tabular-nums">{{
                        formatXof(inv.total)
                    }}</span>
                    <span
                        class="rounded bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{
                            inv.status === 'validated' && inv.payment_status
                                ? t(
                                      `sales.payment_status.${inv.payment_status}`,
                                  )
                                : t(`purchases.status.${inv.status}`)
                        }}</span
                    >
                </Link>
            </li>
        </ul>
        <Pager :prev="invoices.prev_page_url" :next="invoices.next_page_url" />
    </div>
</template>
