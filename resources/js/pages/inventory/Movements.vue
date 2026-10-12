<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import {
    index as stockIndex,
    movements as movementsRoute,
} from '@/routes/stock';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type Movement = {
    id: string;
    occurred_at: string;
    type:
        | 'initial'
        | 'receipt'
        | 'sale'
        | 'customer_return'
        | 'supplier_return'
        | 'transfer_out'
        | 'transfer_in'
        | 'adjustment_in'
        | 'adjustment_out'
        | 'count_adjustment';
    product: { id: string; sku: string; name: string };
    warehouse: string;
    delta: string;
    positive: boolean;
    balance_after: string;
    reason: string | null;
    author: string;
};

const props = defineProps<{
    movements: Paginated<Movement>;
    warehouses: { id: string; code: string; name: string }[];
    filters: { warehouse: string; product: string };
    product: { id: string; sku: string; name: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.movements'), href: movementsRoute() },
        ],
    },
});

const warehouse = ref(props.filters.warehouse);
watch(warehouse, () =>
    router.get(
        movementsRoute().url,
        {
            warehouse: warehouse.value || undefined,
            product: props.filters.product || undefined,
        },
        { preserveState: true, replace: true },
    ),
);

const formatter = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head :title="t('stock.movements_title')" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('stock.movements_title')"
            :description="t('stock.movements_description')"
        />

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('stock.warehouse') }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouse"
                    class="flex h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option value="">{{ t('stock.all_warehouses') }}</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
            </div>
            <p v-if="product" class="flex min-h-11 items-center gap-3 text-sm">
                {{ t('stock.filter_product', { name: product.name }) }}
                <Link
                    :href="
                        movementsRoute({
                            query: {
                                warehouse: filters.warehouse || undefined,
                            },
                        })
                    "
                    class="underline underline-offset-4"
                    >{{ t('stock.clear_filter') }}</Link
                >
            </p>
        </div>

        <EmptyState v-if="movements.data.length === 0">
            {{ t('stock.movements_empty') }}
        </EmptyState>

        <ol v-else class="divide-y rounded-lg border">
            <li
                v-for="m in movements.data"
                :key="m.id"
                class="grid gap-1 p-3 sm:grid-cols-[8.5rem_1fr_auto] sm:items-center sm:gap-4"
            >
                <time
                    :datetime="m.occurred_at"
                    class="text-sm text-muted-foreground"
                    >{{ formatter.format(new Date(m.occurred_at)) }}</time
                >
                <span class="grid min-w-0 gap-0.5">
                    <span class="truncate font-medium">{{
                        m.product.name
                    }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ t(`stock.types.${m.type}`) }} · {{ m.warehouse }} ·
                        {{ t('stock.by', { name: m.author }) }}
                        <template v-if="m.reason">
                            · « {{ m.reason }} »</template
                        >
                    </span>
                </span>
                <span class="grid justify-items-end text-right">
                    <span
                        class="inline-flex items-center gap-1 font-semibold tabular-nums"
                        :class="
                            m.positive
                                ? 'text-[var(--color-brand-teal)]'
                                : 'text-foreground'
                        "
                    >
                        <component
                            :is="m.positive ? ArrowDownLeft : ArrowUpRight"
                            class="size-4"
                            aria-hidden="true"
                        />{{ m.positive ? '+' : '' }}{{ m.delta }}
                    </span>
                    <span class="text-xs text-muted-foreground"
                        >{{ t('stock.balance_after') }} :
                        {{ m.balance_after }}</span
                    >
                </span>
            </li>
        </ol>

        <nav
            v-if="movements.prev_page_url || movements.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="movements.prev_page_url"
                :href="movements.prev_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="movements.next_page_url"
                :href="movements.next_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
