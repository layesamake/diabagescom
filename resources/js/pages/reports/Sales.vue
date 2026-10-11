<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { index as reportsIndex } from '@/routes/reports';

type Row = { label: string; total: number };

const props = defineProps<{
    filters: { from: string; to: string; warehouse: string | null };
    warehouses: { id: string; name: string }[];
    summary: {
        total: number;
        excl_tax: number;
        sales: number;
        average: number;
        cost: number | null;
        margin: number | null;
        receivables: number | null;
        payables: number | null;
        stock_value: number | null;
    };
    byDay: { day: string; total: number; sales: number }[];
    byWarehouse: Row[];
    bySeller: Row[];
    byMethod: {
        method:
            | 'cash'
            | 'wave'
            | 'orange_money'
            | 'card'
            | 'transfer'
            | 'cheque';
        total: number;
    }[];
    topProducts: {
        sku: string;
        label: string;
        quantity: string;
        total: number;
    }[];
    can: { all: boolean; margin: boolean; export: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('reports.title'), href: reportsIndex() }],
    },
});

const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    warehouse: props.filters.warehouse ?? '',
});
const maxDay = computed(() => Math.max(1, ...props.byDay.map((d) => d.total)));
const csvUrl = computed(
    () =>
        reportsIndex({
            query: {
                ...props.filters,
                warehouse: props.filters.warehouse ?? '',
                format: 'csv',
            },
        }).url,
);
const cards = computed(() => [
    { label: t('reports.revenue'), value: formatXof(props.summary.total) },
    { label: t('reports.sales_count'), value: String(props.summary.sales) },
    {
        label: t('reports.average_basket'),
        value: formatXof(props.summary.average),
    },
    ...(props.summary.margin !== null
        ? [
              {
                  label: t('reports.margin'),
                  value: formatXof(props.summary.margin),
              },
          ]
        : []),
    ...(props.summary.receivables !== null
        ? [
              {
                  label: t('reports.receivables'),
                  value: formatXof(props.summary.receivables),
              },
          ]
        : []),
    ...(props.summary.payables !== null
        ? [
              {
                  label: t('reports.payables'),
                  value: formatXof(props.summary.payables),
              },
          ]
        : []),
    ...(props.summary.stock_value !== null
        ? [
              {
                  label: t('reports.stock_value'),
                  value: formatXof(props.summary.stock_value),
              },
          ]
        : []),
]);

function apply(): void {
    router.get(
        reportsIndex({ query: { ...form } }).url,
        {},
        { preserveState: true },
    );
}
</script>

<template>
    <Head :title="t('reports.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('reports.title')"
            :description="
                can.all
                    ? t('reports.description_all')
                    : t('reports.description_own')
            "
        />

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <Input
                v-model="form.from"
                type="date"
                class="h-11 w-40"
                :aria-label="t('accounting.from')"
            />
            <Input
                v-model="form.to"
                type="date"
                class="h-11 w-40"
                :aria-label="t('accounting.to')"
            />
            <select
                v-model="form.warehouse"
                class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                :aria-label="t('sales.warehouse')"
            >
                <option value="">{{ t('reports.all_warehouses') }}</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">
                    {{ w.name }}
                </option>
            </select>
            <Button type="submit" variant="outline" class="h-11">{{
                t('accounting.filter')
            }}</Button>
            <Button v-if="can.export" as-child variant="ghost" class="h-11"
                ><a :href="csvUrl"
                    ><Download class="size-4" />{{
                        t('accounting.export_csv')
                    }}</a
                ></Button
            >
        </form>

        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-lg border p-4"
            >
                <dt class="text-sm text-muted-foreground">{{ card.label }}</dt>
                <dd class="text-xl font-bold tabular-nums">{{ card.value }}</dd>
            </div>
        </dl>

        <!-- Évolution : barres avec valeurs lisibles (pas d'information par la seule couleur). -->
        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('reports.by_day') }}</h2>
            <p v-if="byDay.length === 0" class="text-sm text-muted-foreground">
                {{ t('reports.no_sales') }}
            </p>
            <ul v-else class="space-y-1">
                <li
                    v-for="day in byDay"
                    :key="day.day"
                    class="grid grid-cols-[7rem_1fr_8rem] items-center gap-2 text-sm"
                >
                    <span>{{ formatDate(day.day) }}</span>
                    <span
                        class="h-3 rounded bg-[var(--color-brand-teal)]"
                        :style="{
                            width: `${Math.max(2, (day.total / maxDay) * 100)}%`,
                        }"
                        aria-hidden="true"
                    />
                    <span class="text-right tabular-nums">{{
                        formatXof(day.total)
                    }}</span>
                </li>
            </ul>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="space-y-2">
                <h2 class="font-semibold">{{ t('reports.by_method') }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="m in byMethod"
                        :key="m.method"
                        class="flex justify-between p-2"
                    >
                        <span>{{ t(`methods.${m.method}`) }}</span
                        ><span class="tabular-nums">{{
                            formatXof(m.total)
                        }}</span>
                    </li>
                </ul>
            </section>
            <section class="space-y-2">
                <h2 class="font-semibold">{{ t('reports.by_warehouse') }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="w in byWarehouse"
                        :key="w.label"
                        class="flex justify-between p-2"
                    >
                        <span>{{ w.label }}</span
                        ><span class="tabular-nums">{{
                            formatXof(w.total)
                        }}</span>
                    </li>
                </ul>
            </section>
            <section v-if="can.all" class="space-y-2">
                <h2 class="font-semibold">{{ t('reports.by_seller') }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="s in bySeller"
                        :key="s.label"
                        class="flex justify-between p-2"
                    >
                        <span>{{ s.label }}</span
                        ><span class="tabular-nums">{{
                            formatXof(s.total)
                        }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('reports.top_products') }}</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="p in topProducts"
                    :key="p.sku"
                    class="flex flex-wrap justify-between gap-2 p-2"
                >
                    <span
                        ><span class="font-medium">{{ p.label }}</span>
                        <span class="text-muted-foreground"
                            >{{ p.sku }} ·
                            {{ p.quantity.replace('.000', '') }}</span
                        ></span
                    >
                    <span class="tabular-nums">{{ formatXof(p.total) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
