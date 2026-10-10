<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CustomerPicker, {
    type PickedCustomer,
} from '@/components/CustomerPicker.vue';
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
    index as salesIndex,
    show as salesShow,
    store as salesStore,
    update as salesUpdate,
} from '@/routes/sales';

type DocType = 'quote' | 'order' | 'invoice';
type Line = {
    product: PickedProduct;
    quantity: string;
    unit_price: number;
    discount: string;
};

const props = defineProps<{
    type: DocType;
    document: {
        id: string;
        version: number;
        warehouse_id: string;
        customer: PickedCustomer | null;
        valid_until: string | null;
        notes: string | null;
        lines: {
            product: PickedProduct;
            quantity: string;
            unit_price: number;
            discount: number;
        }[];
    } | null;
    warehouses: { id: string; code: string; name: string }[];
    can: { price_override: boolean; discount: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('sales.title'), href: salesIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const warehouseId = ref(
    props.document?.warehouse_id ?? props.warehouses[0]?.id ?? '',
);
const customer = ref<PickedCustomer | null>(props.document?.customer ?? null);
const validUntil = ref(props.document?.valid_until ?? '');
const notes = ref(props.document?.notes ?? '');
const lines = ref<Line[]>(
    (props.document?.lines ?? []).map((l) => ({
        ...l,
        discount: l.discount ? String(l.discount) : '',
    })),
);
const processing = ref(false);
const title = computed(() =>
    props.document
        ? t('sales.draft_title', { type: t(`sales.types.${props.type}`) })
        : t(`sales.new_${props.type}`),
);

function addLine(product: PickedProduct): void {
    if (!lines.value.some((l) => l.product.id === product.id)) {
        lines.value.push({
            product,
            quantity: '1',
            unit_price: product.sale_price ?? 0,
            discount: '',
        });
    }
}

function lineTotal(line: Line): number {
    return Math.max(
        0,
        lineAmount(line.quantity, line.unit_price) - parseAmount(line.discount),
    );
}

const total = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0));

function submit(): void {
    const payload = {
        type: props.type,
        warehouse_id: warehouseId.value,
        customer_id: customer.value?.id ?? null,
        valid_until: props.type === 'quote' ? validUntil.value || null : null,
        notes: notes.value || null,
        version: props.document?.version ?? null,
        lines: lines.value.map((l) => ({
            product_id: l.product.id,
            quantity: l.quantity,
            unit_price: l.unit_price,
            discount: parseAmount(l.discount),
        })),
    };
    const options = {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    if (props.document) {
        router.put(salesUpdate(props.document.id).url, payload, options);
    } else {
        router.post(salesStore().url, payload, options);
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="document ? salesShow(document.id) : salesIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{ t('sales.back') }}
        </Link>
        <Heading :title="title" />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1">
                <Label for="customer">{{ t('sales.customer') }}</Label>
                <CustomerPicker v-model="customer" input-id="customer" />
                <InputError :message="errors.customer_id" />
            </div>
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('sales.warehouse') }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouseId"
                    class="h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
            </div>
            <div v-if="type === 'quote'" class="grid gap-1">
                <Label for="valid-until"
                    >{{ t('sales.valid_until') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    ></Label
                >
                <Input
                    id="valid-until"
                    v-model="validUntil"
                    type="date"
                    class="h-11"
                />
                <InputError :message="errors.valid_until" />
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('sales.lines') }}</h2>
            <ProductPicker
                input-id="doc-picker"
                :warehouse-id="warehouseId"
                @select="addLine"
            />
            <ul v-if="lines.length" class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="line.product.id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_6rem_8rem_8rem_auto] sm:items-end"
                >
                    <span class="text-sm"
                        ><span class="font-medium">{{ line.product.name }}</span
                        ><span class="block text-muted-foreground"
                            >{{ line.product.sku }} ·
                            {{ formatXof(lineTotal(line)) }}</span
                        ></span
                    >
                    <div class="grid gap-1">
                        <Label :for="`q-${index}`" class="text-xs"
                            >{{ t('sales.quantity') }} ({{
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
                        <Label :for="`p-${index}`" class="text-xs">{{
                            t('sales.unit_price')
                        }}</Label>
                        <Input
                            :id="`p-${index}`"
                            :model-value="String(line.unit_price)"
                            inputmode="numeric"
                            class="h-11 tabular-nums"
                            :disabled="!can.price_override"
                            @update:model-value="
                                line.unit_price = parseAmount(String($event))
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`d-${index}`" class="text-xs">{{
                            t('sales.discount')
                        }}</Label>
                        <Input
                            :id="`d-${index}`"
                            v-model="line.discount"
                            inputmode="numeric"
                            class="h-11 tabular-nums"
                            :disabled="!can.discount"
                        />
                    </div>
                    <Button
                        type="button"
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
            <p class="text-right text-lg font-semibold tabular-nums">
                {{ t('money.total') }} : {{ formatXof(total) }}
            </p>
            <InputError :message="errors.lines ?? errors.business" />
        </section>

        <div class="grid gap-1">
            <Label for="notes"
                >{{ t('sales.notes') }}
                <span class="font-normal text-muted-foreground"
                    >({{ t('common.optional') }})</span
                ></Label
            >
            <textarea
                id="notes"
                v-model="notes"
                maxlength="2000"
                rows="3"
                class="rounded-md border border-input bg-transparent p-3 text-sm"
            />
        </div>

        <Button
            class="h-11 w-full sm:w-fit"
            :disabled="processing || lines.length === 0"
            @click="submit"
        >
            <Spinner v-if="processing" />{{ t('sales.save_draft') }}
        </Button>
    </div>
</template>
