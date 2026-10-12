<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, X } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { formatXof, lineAmount, parseAmount } from '@/lib/money';
import {
    index as ordersIndex,
    show as orderShow,
    store as orderStore,
    update as orderUpdate,
} from '@/routes/purchases/orders';

type Line = { product: PickedProduct; quantity: string; unit_cost: string };

const props = defineProps<{
    order: {
        id: string;
        version: number;
        warehouse_id: string;
        supplier_id: string;
        expected_on: string | null;
        notes: string | null;
        lines: {
            product: PickedProduct;
            quantity: string;
            unit_cost: number;
        }[];
    } | null;
    warehouses: { id: string; code: string; name: string }[];
    suppliers: { id: string; code: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('purchases.orders'), href: ordersIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const supplierId = ref(props.order?.supplier_id ?? '');
const warehouseId = ref(
    props.order?.warehouse_id ?? props.warehouses[0]?.id ?? '',
);
const expectedOn = ref(props.order?.expected_on ?? '');
const notes = ref(props.order?.notes ?? '');
const lines = ref<Line[]>(
    (props.order?.lines ?? []).map((l) => ({
        ...l,
        unit_cost: String(l.unit_cost),
    })),
);
const processing = ref(false);
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';

function addLine(product: PickedProduct): void {
    if (!lines.value.some((l) => l.product.id === product.id)) {
        lines.value.push({
            product,
            quantity: '1',
            unit_cost:
                product.standard_cost !== null
                    ? String(product.standard_cost)
                    : '',
        });
    }
}

const totalExclTax = computed(() =>
    lines.value.reduce(
        (s, l) => s + lineAmount(l.quantity, parseAmount(l.unit_cost)),
        0,
    ),
);

function submit(): void {
    const payload = {
        supplier_id: supplierId.value,
        warehouse_id: warehouseId.value,
        expected_on: expectedOn.value || null,
        notes: notes.value || null,
        version: props.order?.version ?? null,
        lines: lines.value.map((l) => ({
            product_id: l.product.id,
            quantity: l.quantity,
            unit_cost: parseAmount(l.unit_cost),
        })),
    };
    const options = {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    if (props.order) {
        router.put(orderUpdate(props.order.id).url, payload, options);
    } else {
        router.post(orderStore().url, payload, options);
    }
}
</script>

<template>
    <Head :title="t('purchases.new_order')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="order ? orderShow(order.id) : ordersIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.back_orders')
            }}
        </Link>
        <Heading :title="t('purchases.new_order')" />

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-1">
                <Label for="supplier">{{ t('purchases.supplier') }}</Label>
                <select id="supplier" v-model="supplierId" :class="selectClass">
                    <option value="" disabled>
                        {{ t('purchases.choose_supplier') }}
                    </option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">
                        {{ s.name }} ({{ s.code }})
                    </option>
                </select>
                <InputError :message="errors.supplier_id" />
            </div>
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('purchases.warehouse') }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouseId"
                    :class="selectClass"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="expected"
                    >{{ t('purchases.expected_on') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    ></Label
                >
                <Input
                    id="expected"
                    v-model="expectedOn"
                    type="date"
                    class="h-11"
                />
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('purchases.lines') }}</h2>
            <ProductPicker
                input-id="po-picker"
                stocked-only
                @select="addLine"
            />
            <ul v-if="lines.length" class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="line.product.id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_7rem_9rem_auto] sm:items-end"
                >
                    <span class="text-sm"
                        ><span class="font-medium">{{ line.product.name }}</span
                        ><span class="block text-muted-foreground"
                            >{{ line.product.sku }} ·
                            {{
                                formatXof(
                                    lineAmount(
                                        line.quantity,
                                        parseAmount(line.unit_cost),
                                    ),
                                )
                            }}
                            HT</span
                        ></span
                    >
                    <div class="grid gap-1">
                        <Label :for="`q-${index}`" class="text-xs"
                            >{{ t('purchases.quantity') }} ({{
                                line.product.unit
                            }})</Label
                        >
                        <Input
                            :id="`q-${index}`"
                            v-model="line.quantity"
                            :inputmode="
                                line.product.decimals ? 'decimal' : 'numeric'
                            "
                            class="h-11 tabular-nums"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`c-${index}`" class="text-xs">{{
                            t('purchases.unit_cost')
                        }}</Label>
                        <Input
                            :id="`c-${index}`"
                            v-amount
                            v-model="line.unit_cost"
                            inputmode="numeric"
                            class="h-11 tabular-nums"
                        />
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="
                            t('sales.remove_line', { name: line.product.name })
                        "
                        @click="lines.splice(index, 1)"
                        ><X class="size-4"
                    /></Button>
                </li>
            </ul>
            <p class="text-right font-semibold tabular-nums">
                {{ t('money.total_excl_tax') }} : {{ formatXof(totalExclTax) }}
            </p>
            <InputError :message="errors.lines ?? errors.business" />
        </section>

        <div class="grid gap-1">
            <Label for="notes"
                >{{ t('purchases.notes') }}
                <span class="font-normal text-muted-foreground"
                    >({{ t('common.optional') }})</span
                ></Label
            >
            <textarea
                id="notes"
                v-model="notes"
                rows="3"
                maxlength="2000"
                class="rounded-md border border-input bg-transparent p-3 text-sm"
            />
        </div>

        <Button
            class="h-11 w-full sm:w-fit"
            :disabled="processing || !supplierId || lines.length === 0"
            @click="submit"
        >
            <Spinner v-if="processing" />{{ t('purchases.save_draft') }}
        </Button>
    </div>
</template>
