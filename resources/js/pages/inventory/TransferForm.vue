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
import { index as stockIndex } from '@/routes/stock';
import {
    index as transfersIndex,
    store as transferStore,
} from '@/routes/transfers';

type WarehouseOption = { id: string; code: string; name: string };

const props = defineProps<{
    sources: WarehouseOption[];
    destinations: WarehouseOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.transfers'), href: transfersIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const source = ref(props.sources[0]?.id ?? '');
const destination = ref(
    props.destinations.find((d) => d.id !== source.value)?.id ?? '',
);
const notes = ref('');
const lines = ref<{ product: PickedProduct; quantity: string }[]>([]);
const processing = ref(false);
const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';

function addLine(product: PickedProduct): void {
    if (!lines.value.some((l) => l.product.id === product.id)) {
        lines.value.push({ product, quantity: '' });
    }
}

function submit(): void {
    router.post(
        transferStore().url,
        {
            source_warehouse_id: source.value,
            destination_warehouse_id: destination.value,
            notes: notes.value || null,
            lines: lines.value.map((l) => ({
                product_id: l.product.id,
                quantity: l.quantity,
            })),
        },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="t('transfers.new')" />

    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-6">
        <Link
            :href="transfersIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('transfers.back')
            }}
        </Link>
        <Heading
            :title="t('transfers.new')"
            :description="t('transfers.description')"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1">
                <Label for="source">{{ t('transfers.source') }}</Label>
                <select id="source" v-model="source" :class="selectClass">
                    <option v-for="w in sources" :key="w.id" :value="w.id">
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="destination">{{
                    t('transfers.destination')
                }}</Label>
                <select
                    id="destination"
                    v-model="destination"
                    :class="selectClass"
                >
                    <option
                        v-for="w in destinations.filter((d) => d.id !== source)"
                        :key="w.id"
                        :value="w.id"
                    >
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
                <InputError :message="errors.destination_warehouse_id" />
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('stock.lines') }}</h2>
            <ProductPicker
                input-id="transfer-picker"
                stocked-only
                @select="addLine"
            />
            <ul v-if="lines.length" class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="line.product.id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_9rem_auto] sm:items-end"
                >
                    <span class="text-sm"
                        ><span class="font-medium">{{ line.product.name }}</span
                        ><span class="block text-muted-foreground">{{
                            line.product.sku
                        }}</span></span
                    >
                    <div class="grid gap-1">
                        <Label :for="`tq-${index}`" class="text-xs"
                            >{{ t('stock.quantity') }} ({{
                                line.product.unit
                            }})</Label
                        >
                        <Input
                            :id="`tq-${index}`"
                            v-model="line.quantity"
                            :inputmode="
                                line.product.decimals ? 'decimal' : 'numeric'
                            "
                            class="h-11 tabular-nums"
                        />
                    </div>
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
            <InputError :message="errors.lines ?? errors.business" />
        </section>

        <div class="grid gap-1">
            <Label for="notes"
                >{{ t('transfers.notes') }}
                <span class="font-normal text-muted-foreground"
                    >({{ t('common.optional') }})</span
                ></Label
            >
            <Input id="notes" v-model="notes" maxlength="1000" class="h-11" />
        </div>

        <Button
            class="h-11 w-full sm:w-fit"
            :disabled="processing || lines.length === 0 || !destination"
            @click="submit"
        >
            <Spinner v-if="processing" />{{ t('transfers.create') }}
        </Button>
    </div>
</template>
