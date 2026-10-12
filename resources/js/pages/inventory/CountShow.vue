<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCheck, Trash2 } from '@lucide/vue';
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
    cancel as cancelRoute,
    index as countsIndex,
    validate as validateRoute,
} from '@/routes/counts';
import {
    destroy as lineDestroy,
    store as lineStore,
} from '@/routes/counts/lines';
import { index as stockIndex } from '@/routes/stock';
import { confirmAction } from '@/composables/useConfirm';

type Line = {
    id: string;
    product_id: string;
    sku: string;
    name: string;
    unit: string;
    counted: string;
    theoretical: string;
    variance: string;
    has_variance: boolean;
    reason: string | null;
};

const props = defineProps<{
    count: {
        id: string;
        number: string | null;
        status: 'draft' | 'validated' | 'cancelled';
        warehouse: { name: string; code: string };
        lines: Line[];
    };
    can: { edit: boolean; validate: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.counts'), href: countsIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const validateKey = crypto.randomUUID();

// Article en cours de comptage (après scan ou recherche).
const current = ref<PickedProduct | null>(null);
const quantity = ref('');
const reason = ref('');

const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function pick(product: PickedProduct): void {
    const existing = props.count.lines.find((l) => l.product_id === product.id);
    current.value = product;
    quantity.value = existing ? existing.counted.replace(/\s/g, '') : '';
    reason.value = existing?.reason ?? '';
}

function saveLine(): void {
    if (!current.value) {
        return;
    }

    router.post(
        lineStore(props.count.id).url,
        {
            product_id: current.value.id,
            counted_quantity: quantity.value,
            reason: reason.value || null,
        },
        {
            ...options,
            onSuccess: () => {
                if (!errors.value.counted_quantity) {
                    current.value = null;
                }
            },
        },
    );
}

function editReason(line: Line): void {
    current.value = {
        id: line.product_id,
        sku: line.sku,
        name: line.name,
        unit: line.unit,
        decimals: true,
        standard_cost: null,
    };
    quantity.value = line.counted.replace(/\s/g, '');
    reason.value = line.reason ?? '';
}

function removeLine(line: Line): void {
    router.delete(
        lineDestroy({ count: props.count.id, line: line.id }).url,
        options,
    );
}

async function validateCount(): Promise<void> {
    if (
        await confirmAction({
            title: t('counts.validate_confirm'),
            confirmLabel: t('confirm.validate'),
            tone: 'primary',
        })
    ) {
        router.post(
            validateRoute(props.count.id).url,
            { idempotency_key: validateKey },
            options,
        );
    }
}
</script>

<template>
    <Head :title="count.number ?? t('counts.status.draft')" />

    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-6">
        <Link
            :href="countsIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('counts.back')
            }}
        </Link>

        <Heading
            :title="
                count.number
                    ? t('counts.title_show', { number: count.number })
                    : t('counts.in_progress', {
                          warehouse: count.warehouse.name,
                      })
            "
            :description="t(`counts.status.${count.status}`)"
        />

        <section v-if="can.edit" class="space-y-3 rounded-lg border p-4">
            <h2 class="font-semibold">{{ t('counts.add') }}</h2>
            <ProductPicker
                input-id="count-picker"
                stocked-only
                @select="pick"
            />
            <div v-if="current" class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                <p class="text-sm sm:col-span-2">
                    <span class="font-medium">{{ current.name }}</span> ·
                    {{ current.sku }}
                </p>
                <div class="grid gap-1 sm:order-2">
                    <Label for="counted"
                        >{{ t('counts.quantity') }} ({{ current.unit }})</Label
                    >
                    <Input
                        id="counted"
                        v-model="quantity"
                        inputmode="decimal"
                        class="h-11 tabular-nums"
                        v-focus
                        @keydown.enter.prevent="saveLine"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="line-reason"
                        >{{ t('counts.reason') }}
                        <span class="font-normal text-muted-foreground"
                            >({{ t('common.optional') }})</span
                        ></Label
                    >
                    <Input
                        id="line-reason"
                        v-model="reason"
                        maxlength="500"
                        class="h-11"
                    />
                </div>
                <InputError
                    :message="errors.counted_quantity"
                    class="sm:col-span-2"
                />
                <Button
                    class="h-11 sm:col-span-2 sm:w-fit"
                    :disabled="processing || quantity === ''"
                    @click="saveLine"
                    >{{ t('counts.save_line') }}</Button
                >
            </div>
            <p class="text-xs text-muted-foreground">
                {{ t('counts.theoretical_hint') }}
            </p>
        </section>

        <ul v-if="count.lines.length" class="divide-y rounded-lg border">
            <li
                v-for="line in count.lines"
                :key="line.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3"
            >
                <span class="min-w-40 flex-1"
                    ><span class="font-medium">{{ line.name }}</span
                    ><span class="block text-sm text-muted-foreground">{{
                        line.sku
                    }}</span></span
                >
                <span class="text-sm tabular-nums"
                    >{{ t('counts.counted') }} : {{ line.counted }}</span
                >
                <span class="text-sm text-muted-foreground tabular-nums"
                    >{{ t('counts.theoretical') }} :
                    {{ line.theoretical }}</span
                >
                <span
                    class="text-sm font-semibold tabular-nums"
                    :class="
                        line.has_variance
                            ? 'text-warning'
                            : 'text-muted-foreground'
                    "
                    >{{ t('counts.variance') }} : {{ line.variance }}</span
                >
                <button
                    v-if="can.edit && line.has_variance"
                    type="button"
                    class="min-h-11 w-full text-left text-sm sm:w-auto"
                    :class="
                        line.reason
                            ? 'text-muted-foreground'
                            : 'font-medium text-destructive underline'
                    "
                    @click="editReason(line)"
                >
                    {{
                        line.reason ? `« ${line.reason} »` : t('counts.reason')
                    }}
                </button>
                <span
                    v-else-if="line.reason"
                    class="w-full text-sm text-muted-foreground"
                    >« {{ line.reason }} »</span
                >
                <Button
                    v-if="can.edit"
                    variant="ghost"
                    size="icon"
                    class="size-11"
                    :aria-label="`${t('counts.remove')} ${line.name}`"
                    @click="removeLine(line)"
                    ><Trash2 class="size-4"
                /></Button>
            </li>
        </ul>

        <InputError :message="errors.business" />

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="can.validate"
                class="h-11"
                :disabled="processing || count.lines.length === 0"
                @click="validateCount"
            >
                <Spinner v-if="processing" /><CheckCheck class="size-4" />{{
                    t('counts.validate')
                }}
            </Button>
            <Button
                v-if="can.edit"
                variant="outline"
                class="h-11"
                :disabled="processing"
                @click="router.post(cancelRoute(count.id).url, {}, options)"
                >{{ t('counts.cancel') }}</Button
            >
        </div>
    </div>
</template>
