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
import {
    index as requestsIndex,
    show as requestShow,
    store as requestStore,
    update as requestUpdate,
} from '@/routes/purchases/requests';

type Line = { product: PickedProduct; quantity: string; note: string };

const props = defineProps<{
    purchaseRequest: {
        id: string;
        version: number;
        warehouse_id: string;
        needed_on: string | null;
        notes: string | null;
        lines: {
            product: PickedProduct;
            quantity: string;
            note: string | null;
        }[];
    } | null;
    warehouses: { id: string; code: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('purchases.requests.title'), href: requestsIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const warehouseId = ref(
    props.purchaseRequest?.warehouse_id ?? props.warehouses[0]?.id ?? '',
);
const neededOn = ref(props.purchaseRequest?.needed_on ?? '');
const notes = ref(props.purchaseRequest?.notes ?? '');
const lines = ref<Line[]>(
    (props.purchaseRequest?.lines ?? []).map((l) => ({
        product: l.product,
        quantity: l.quantity,
        note: l.note ?? '',
    })),
);
const processing = ref(false);
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';

function addLine(product: PickedProduct): void {
    if (!lines.value.some((l) => l.product.id === product.id)) {
        lines.value.push({ product, quantity: '1', note: '' });
    }
}

function submit(): void {
    const payload = {
        warehouse_id: warehouseId.value,
        needed_on: neededOn.value || null,
        notes: notes.value || null,
        version: props.purchaseRequest?.version ?? null,
        lines: lines.value.map((l) => ({
            product_id: l.product.id,
            quantity: l.quantity,
            note: l.note || null,
        })),
    };
    const options = {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    if (props.purchaseRequest) {
        router.put(
            requestUpdate(props.purchaseRequest.id).url,
            payload,
            options,
        );
    } else {
        router.post(requestStore().url, payload, options);
    }
}
</script>

<template>
    <Head :title="t('purchases.requests.new')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="
                purchaseRequest
                    ? requestShow(purchaseRequest.id)
                    : requestsIndex()
            "
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.requests.back')
            }}
        </Link>
        <Heading
            :title="t('purchases.requests.new')"
            :description="t('purchases.requests.form_hint')"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1">
                <Label for="warehouse">{{
                    t('purchases.requests.warehouse')
                }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouseId"
                    :class="selectClass"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select>
                <InputError :message="errors.warehouse_id" />
            </div>
            <div class="grid gap-1">
                <Label for="needed"
                    >{{ t('purchases.requests.needed_on') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    ></Label
                >
                <Input
                    id="needed"
                    v-model="neededOn"
                    type="date"
                    class="h-11"
                />
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('purchases.lines') }}</h2>
            <ProductPicker
                input-id="pr-picker"
                stocked-only
                @select="addLine"
            />
            <ul v-if="lines.length" class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="line.product.id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_7rem_1fr_auto] sm:items-end"
                >
                    <span class="text-sm"
                        ><span class="font-medium">{{ line.product.name }}</span
                        ><span class="block text-muted-foreground">{{
                            line.product.sku
                        }}</span></span
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
                        <Label :for="`n-${index}`" class="text-xs">{{
                            t('purchases.requests.line_note')
                        }}</Label>
                        <Input
                            :id="`n-${index}`"
                            v-model="line.note"
                            maxlength="200"
                            class="h-11"
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
            <InputError :message="errors.lines ?? errors.business" />
        </section>

        <div class="grid gap-1">
            <Label for="notes"
                >{{ t('purchases.requests.reason_label') }}
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
            :disabled="processing || lines.length === 0"
            @click="submit"
        >
            <Spinner v-if="processing" />{{ t('purchases.save_draft') }}
        </Button>
    </div>
</template>
