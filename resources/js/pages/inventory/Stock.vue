<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ClipboardList,
    History,
    Minus,
    Plus,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ProductPicker, {
    type PickedProduct,
} from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { index as countsIndex } from '@/routes/counts';
import {
    adjust as adjustRoute,
    index as stockIndex,
    movements as movementsRoute,
    reorder_point as reorderRoute,
} from '@/routes/stock';
import { index as transfersIndex } from '@/routes/transfers';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type Row = {
    id: string;
    sku: string;
    name: string;
    unit: string;
    decimals: boolean;
    quantity: string;
    quantity_display: string;
    reorder_point: string | null;
    low: boolean;
    negative: boolean;
    average_cost: number | null;
    value: number | null;
};

type Line = { product: PickedProduct; quantity: string; unit_cost: string };

const props = defineProps<{
    warehouses: { id: string; code: string; name: string }[];
    warehouseId: string;
    rows: Paginated<Row>;
    filters: { q: string; low: boolean };
    lowCount: number;
    can: { adjust: boolean; costs: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: t('stock.title'), href: stockIndex() }] },
});

const page = usePage();
const warehouse = ref(props.warehouseId);
const q = ref(props.filters.q);
const lowOnly = ref(props.filters.low);
const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';

function reload(): void {
    router.get(
        stockIndex().url,
        {
            warehouse: warehouse.value,
            q: q.value || undefined,
            low: lowOnly.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 350);
});
watch([warehouse, lowOnly], reload);

// Panneau de saisie : stock initial ou ajustement.
const mode = ref<'initial' | 'adjustment' | null>(null);
const lines = ref<Line[]>([]);
const reason = ref('');
const processing = ref(false);
const idempotencyKey = ref(crypto.randomUUID());
const errors = computed(() => page.props.errors as Record<string, string>);

function openPanel(next: 'initial' | 'adjustment'): void {
    mode.value = next;
    lines.value = [];
    reason.value = '';
    idempotencyKey.value = crypto.randomUUID();
}

function addLine(product: PickedProduct): void {
    if (!lines.value.some((l) => l.product.id === product.id)) {
        lines.value.push({
            product,
            quantity: '',
            unit_cost:
                product.standard_cost != null
                    ? String(product.standard_cost)
                    : '',
        });
    }
}

function submit(): void {
    router.post(
        adjustRoute().url,
        {
            mode: mode.value,
            warehouse_id: warehouse.value,
            idempotency_key: idempotencyKey.value,
            reason: reason.value || null,
            lines: lines.value.map((l) => ({
                product_id: l.product.id,
                quantity: l.quantity,
                unit_cost:
                    mode.value === 'initial' && l.unit_cost !== ''
                        ? Number(l.unit_cost.replace(/\s/g, ''))
                        : null,
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                if (!Object.keys(errors.value).length) {
                    mode.value = null;
                }
            },
        },
    );
}

// Seuil d'alerte en ligne.
const editingReorder = ref<string | null>(null);
const reorderValue = ref('');

function editReorder(row: Row): void {
    editingReorder.value = row.id;
    reorderValue.value = row.reorder_point ?? '';
}

function saveReorder(row: Row): void {
    router.post(
        reorderRoute(row.id).url,
        { warehouse_id: warehouse.value, reorder_point: reorderValue.value },
        {
            preserveScroll: true,
            onSuccess: () => (editingReorder.value = null),
        },
    );
}

const formatQty = (raw: string | null) =>
    raw === null ? '—' : raw.replace(/\.?0+$/, '').replace('.', ',');
</script>

<template>
    <Head :title="t('stock.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('stock.title')"
                :description="t('stock.description')"
            />
            <nav class="flex flex-wrap gap-2" :aria-label="t('stock.title')">
                <Button as-child variant="outline" class="h-11"
                    ><Link :href="movementsRoute({ query: { warehouse } })"
                        ><History class="size-4" />{{
                            t('stock.movements')
                        }}</Link
                    ></Button
                >
                <Button as-child variant="outline" class="h-11"
                    ><Link :href="transfersIndex()"
                        ><ArrowLeftRight class="size-4" />{{
                            t('stock.transfers')
                        }}</Link
                    ></Button
                >
                <Button as-child variant="outline" class="h-11"
                    ><Link :href="countsIndex()"
                        ><ClipboardList class="size-4" />{{
                            t('stock.counts')
                        }}</Link
                    ></Button
                >
            </nav>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[16rem_1fr_auto]">
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('stock.warehouse') }}</Label>
                <select id="warehouse" v-model="warehouse" :class="selectClass">
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="q">{{ t('stock.search') }}</Label>
                <Input id="q" v-model="q" type="search" class="h-11" />
            </div>
            <label
                class="flex min-h-11 items-center gap-3 self-end text-sm sm:col-span-2 xl:col-span-1"
            >
                <input v-model="lowOnly" type="checkbox" class="size-5" />{{
                    t('stock.low_only')
                }}
            </label>
        </div>

        <p
            v-if="lowCount > 0"
            role="status"
            class="flex items-center gap-2 rounded-lg border border-warning-border bg-warning-soft p-3 text-sm text-warning-soft-foreground"
        >
            <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />{{
                t('stock.low_count', { count: lowCount })
            }}
        </p>

        <div v-if="can.adjust" class="flex flex-wrap gap-2">
            <Button class="h-11" @click="openPanel('initial')"
                ><Plus class="size-4" />{{ t('stock.initial') }}</Button
            >
            <Button
                variant="outline"
                class="h-11"
                @click="openPanel('adjustment')"
                ><Minus class="size-4" />{{ t('stock.adjust') }}</Button
            >
        </div>

        <section
            v-if="mode"
            class="space-y-4 rounded-lg border p-4"
            aria-live="polite"
        >
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 class="font-semibold">
                        {{
                            mode === 'initial'
                                ? t('stock.initial_title')
                                : t('stock.adjust_title')
                        }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            mode === 'initial'
                                ? t('stock.initial_hint')
                                : t('stock.adjust_hint')
                        }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-11"
                    :aria-label="t('stock.cancel')"
                    @click="mode = null"
                    ><X class="size-4"
                /></Button>
            </div>

            <ProductPicker
                input-id="line-picker"
                stocked-only
                @select="addLine"
            />

            <ul v-if="lines.length" class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="line.product.id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_9rem_9rem_auto] sm:items-end"
                >
                    <span class="text-sm">
                        <span class="font-medium">{{ line.product.name }}</span>
                        <span class="block text-muted-foreground">{{
                            line.product.sku
                        }}</span>
                    </span>
                    <div class="grid gap-1">
                        <Label :for="`qty-${index}`" class="text-xs"
                            >{{ t('stock.quantity') }} ({{
                                line.product.unit
                            }})</Label
                        >
                        <Input
                            :id="`qty-${index}`"
                            v-model="line.quantity"
                            :inputmode="
                                line.product.decimals ? 'decimal' : 'numeric'
                            "
                            class="h-11 tabular-nums"
                            required
                        />
                    </div>
                    <div v-if="mode === 'initial'" class="grid gap-1">
                        <Label :for="`cost-${index}`" class="text-xs">{{
                            t('stock.unit_cost')
                        }}</Label>
                        <Input
                            :id="`cost-${index}`"
                            v-model="line.unit_cost"
                            inputmode="numeric"
                            class="h-11 tabular-nums"
                        />
                    </div>
                    <span v-else />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="
                            t('stock.remove_line', { name: line.product.name })
                        "
                        @click="lines.splice(index, 1)"
                        ><X class="size-4"
                    /></Button>
                </li>
            </ul>
            <InputError
                :message="
                    errors.lines ??
                    errors['lines.0.quantity'] ??
                    errors.business
                "
            />

            <div class="grid gap-1">
                <Label for="reason"
                    >{{ t('stock.reason')
                    }}<span
                        v-if="mode === 'initial'"
                        class="font-normal text-muted-foreground"
                    >
                        ({{ t('common.optional') }})</span
                    ></Label
                >
                <Input
                    id="reason"
                    v-model="reason"
                    maxlength="500"
                    class="h-11"
                    :placeholder="t('stock.reason_placeholder')"
                    :required="mode === 'adjustment'"
                />
                <InputError :message="errors.reason" />
            </div>

            <Button
                class="h-11 w-full sm:w-fit"
                :disabled="processing || lines.length === 0"
                @click="submit"
            >
                <Spinner v-if="processing" />{{
                    mode === 'initial'
                        ? t('stock.submit_initial')
                        : t('stock.submit_adjust')
                }}
            </Button>
        </section>

        <EmptyState v-if="rows.data.length === 0" :hint="t('empty.stock')">
            {{ t('stock.empty') }}
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="row in rows.data"
                :key="row.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3"
            >
                <span class="grid min-w-40 flex-1 gap-0.5">
                    <span class="font-medium">{{ row.name }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        row.sku
                    }}</span>
                </span>
                <span class="grid justify-items-end text-right">
                    <span
                        class="font-semibold tabular-nums"
                        :class="{ 'text-destructive': row.negative }"
                        >{{ row.quantity_display }} {{ row.unit }}</span
                    >
                    <span
                        v-if="row.negative"
                        class="text-xs font-medium text-destructive"
                        >{{ t('stock.negative') }}</span
                    >
                    <span
                        v-else-if="row.low"
                        class="inline-flex items-center gap-1 text-xs font-medium text-warning"
                        ><TriangleAlert class="size-3" aria-hidden="true" />{{
                            t('stock.low')
                        }}</span
                    >
                </span>
                <span
                    v-if="can.costs"
                    class="grid min-w-28 justify-items-end text-right text-sm text-muted-foreground"
                >
                    <span
                        >{{ t('stock.average_cost') }} :
                        {{ formatXof(row.average_cost ?? 0) }}</span
                    >
                    <span
                        >{{ t('stock.value') }} :
                        {{ formatXof(row.value ?? 0) }}</span
                    >
                </span>
                <span class="flex items-center gap-2 text-sm">
                    <template v-if="editingReorder === row.id">
                        <Label :for="`reorder-${row.id}`" class="sr-only">{{
                            t('stock.set_reorder', { name: row.name })
                        }}</Label>
                        <Input
                            :id="`reorder-${row.id}`"
                            v-model="reorderValue"
                            inputmode="decimal"
                            class="h-11 w-24"
                            @keydown.enter.prevent="saveReorder(row)"
                        />
                        <Button class="h-11" @click="saveReorder(row)">{{
                            t('stock.save')
                        }}</Button>
                    </template>
                    <button
                        v-else
                        type="button"
                        class="min-h-11 rounded-md px-2 text-muted-foreground hover:bg-accent disabled:hover:bg-transparent"
                        :disabled="!can.adjust"
                        :aria-label="t('stock.set_reorder', { name: row.name })"
                        @click="editReorder(row)"
                    >
                        {{ t('stock.reorder_point') }} :
                        {{ formatQty(row.reorder_point) }}
                    </button>
                    <Link
                        :href="
                            movementsRoute({
                                query: { warehouse, product: row.id },
                            })
                        "
                        class="inline-flex min-h-11 items-center px-2 underline underline-offset-4"
                        >{{ t('stock.history') }}</Link
                    >
                </span>
            </li>
        </ul>

        <nav
            v-if="rows.prev_page_url || rows.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="rows.prev_page_url"
                :href="rows.prev_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="rows.next_page_url"
                :href="rows.next_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
