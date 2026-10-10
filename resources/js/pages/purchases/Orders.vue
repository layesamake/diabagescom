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
    create as orderCreate,
    index as ordersIndex,
    show as orderShow,
} from '@/routes/purchases/orders';
import type { Paginated } from '@/types';

type Status =
    | 'draft'
    | 'confirmed'
    | 'partially_received'
    | 'received'
    | 'closed'
    | 'cancelled';
type Order = {
    id: string;
    number: string | null;
    status: Status;
    supplier: { name: string };
    warehouse: { name: string };
    total: number;
    created_at: string | null;
};

defineProps<{
    orders: Paginated<Order>;
    filters: { status: string };
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('purchases.orders'), href: ordersIndex() }],
    },
});

const tabs: { value: string; label: string }[] = [
    { value: '', label: t('purchases.all') },
    { value: 'open', label: t('purchases.filter_open') },
    { value: 'draft', label: t('purchases.status.draft') },
    { value: 'received', label: t('purchases.status.received') },
];

function filter(status: string): void {
    router.get(
        ordersIndex({ query: status ? { status } : {} }).url,
        {},
        { preserveState: true },
    );
}

const badge: Record<Status, string> = {
    draft: 'bg-muted text-foreground',
    confirmed: 'bg-sky-100 text-sky-950 dark:bg-sky-950 dark:text-sky-50',
    partially_received:
        'bg-amber-100 text-amber-950 dark:bg-amber-950 dark:text-amber-50',
    received:
        'bg-emerald-100 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-50',
    closed: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <Head :title="t('purchases.orders')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('purchases.orders')"
                :description="t('purchases.orders_description')"
            />
            <Button v-if="can.create" as-child class="h-11"
                ><Link :href="orderCreate()"
                    ><Plus class="size-4" />{{ t('purchases.new_order') }}</Link
                ></Button
            >
        </div>

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

        <p
            v-if="orders.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('purchases.empty') }}
        </p>
        <ul v-else class="divide-y rounded-lg border">
            <li v-for="order in orders.data" :key="order.id">
                <Link
                    :href="orderShow(order.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-36 font-medium">{{
                        order.number ?? t('purchases.status.draft')
                    }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        order.supplier.name
                    }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ order.warehouse.name }} ·
                        {{ formatDate(order.created_at) }}</span
                    >
                    <span class="font-semibold tabular-nums">{{
                        formatXof(order.total)
                    }}</span>
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="badge[order.status]"
                        >{{ t(`purchases.status.${order.status}`) }}</span
                    >
                </Link>
            </li>
        </ul>
        <Pager :prev="orders.prev_page_url" :next="orders.next_page_url" />
    </div>
</template>
