<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { formatXof, lineAmount, parseAmount } from '@/lib/money';
import {
    index as invoicesIndex,
    show as invoiceShow,
    store as invoiceStore,
    update as invoiceUpdate,
} from '@/routes/purchases/invoices';

type InvoiceLine = {
    product_id: string | null;
    label: string;
    quantity: string;
    unit_cost: number | string;
    tax_rate_basis_points: number;
};
type Header = {
    supplier_id: string;
    warehouse_id: string;
    goods_receipt_id: string | null;
    purchase_order_id: string | null;
    lines: InvoiceLine[];
};

const props = defineProps<{
    invoice:
        | (Header & {
              id: string;
              version: number;
              supplier_reference: string | null;
              issue_date: string | null;
              due_date: string | null;
              notes: string | null;
          })
        | null;
    prefill: (Header & { source: string | null }) | null;
    warehouses: { id: string; code: string; name: string }[];
    suppliers: { id: string; code: string; name: string }[];
    taxRates: { id: string; name: string; rate_basis_points: number }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('purchases.invoices'), href: invoicesIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const base = props.invoice ?? props.prefill;
const supplierId = ref(base?.supplier_id ?? '');
const warehouseId = ref(base?.warehouse_id ?? props.warehouses[0]?.id ?? '');
const reference = ref(props.invoice?.supplier_reference ?? '');
const issueDate = ref(
    props.invoice?.issue_date ?? new Date().toISOString().slice(0, 10),
);
const dueDate = ref(props.invoice?.due_date ?? '');
const notes = ref(props.invoice?.notes ?? '');
const defaultRate = props.taxRates[0]?.rate_basis_points ?? 1800;
const lines = ref(
    (base?.lines ?? []).map((l) => ({ ...l, unit_cost: String(l.unit_cost) })),
);
const processing = ref(false);
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';

function addLine(): void {
    lines.value.push({
        product_id: null,
        label: '',
        quantity: '1',
        unit_cost: '',
        tax_rate_basis_points: defaultRate,
    });
}

if (lines.value.length === 0) {
    addLine();
}

function exclTax(line: { quantity: string; unit_cost: string }): number {
    return lineAmount(line.quantity, parseAmount(line.unit_cost));
}

// Aperçu : TVA arrondie au franc par ligne, comme le serveur.
const totals = computed(() =>
    lines.value.reduce(
        (acc, l) => {
            const ht = exclTax(l);
            const tax = Math.floor(
                (2 * ht * l.tax_rate_basis_points + 10000) / 20000,
            );

            return { excl: acc.excl + ht, tax: acc.tax + tax };
        },
        { excl: 0, tax: 0 },
    ),
);

function submit(): void {
    const payload = {
        supplier_id: supplierId.value,
        warehouse_id: warehouseId.value,
        supplier_reference: reference.value || null,
        issue_date: issueDate.value || null,
        due_date: dueDate.value || null,
        goods_receipt_id: base?.goods_receipt_id ?? null,
        purchase_order_id: base?.purchase_order_id ?? null,
        notes: notes.value || null,
        version: props.invoice?.version ?? null,
        lines: lines.value.map((l) => ({
            product_id: l.product_id,
            label: l.label,
            quantity: l.quantity,
            unit_cost: parseAmount(l.unit_cost),
            tax_rate_basis_points: l.tax_rate_basis_points,
        })),
    };
    const options = {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    if (props.invoice) {
        router.put(invoiceUpdate(props.invoice.id).url, payload, options);
    } else {
        router.post(invoiceStore().url, payload, options);
    }
}
</script>

<template>
    <Head :title="t('purchases.new_invoice')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="invoice ? invoiceShow(invoice.id) : invoicesIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.back_invoices')
            }}
        </Link>
        <Heading
            :title="t('purchases.new_invoice')"
            :description="
                prefill?.source
                    ? t('purchases.from', { source: prefill.source })
                    : undefined
            "
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1">
                <Label for="supplier">{{ t('purchases.supplier') }}</Label>
                <select
                    id="supplier"
                    v-model="supplierId"
                    :class="selectClass"
                    :disabled="Boolean(prefill)"
                >
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
                <Label for="reference">{{
                    t('purchases.supplier_reference')
                }}</Label>
                <Input
                    id="reference"
                    v-model="reference"
                    maxlength="64"
                    class="h-11"
                />
                <InputError :message="errors.supplier_reference" />
            </div>
            <div class="grid gap-1">
                <Label for="issue">{{ t('purchases.issue_date') }}</Label>
                <Input
                    id="issue"
                    v-model="issueDate"
                    type="date"
                    class="h-11"
                />
            </div>
            <div class="grid gap-1">
                <Label for="due"
                    >{{ t('purchases.due_date') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    ></Label
                >
                <Input id="due" v-model="dueDate" type="date" class="h-11" />
                <InputError :message="errors.due_date" />
            </div>
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('sales.warehouse') }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouseId"
                    :class="selectClass"
                    :disabled="Boolean(prefill)"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select>
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('purchases.lines') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="(line, index) in lines"
                    :key="index"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_6rem_8rem_7rem_auto] sm:items-end"
                >
                    <div class="grid gap-1">
                        <Label :for="`l-${index}`" class="text-xs">{{
                            t('purchases.label')
                        }}</Label>
                        <Input
                            :id="`l-${index}`"
                            v-model="line.label"
                            maxlength="200"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`q-${index}`" class="text-xs">{{
                            t('purchases.quantity')
                        }}</Label>
                        <Input
                            :id="`q-${index}`"
                            v-model="line.quantity"
                            inputmode="decimal"
                            class="h-11 tabular-nums"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`c-${index}`" class="text-xs">{{
                            t('purchases.unit_cost')
                        }}</Label>
                        <Input
                            :id="`c-${index}`"
                            v-model="line.unit_cost"
                            inputmode="numeric"
                            class="h-11 tabular-nums"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`t-${index}`" class="text-xs">{{
                            t('purchases.tax_rate')
                        }}</Label>
                        <select
                            :id="`t-${index}`"
                            v-model="line.tax_rate_basis_points"
                            :class="selectClass"
                        >
                            <option
                                v-for="rate in taxRates"
                                :key="rate.id"
                                :value="rate.rate_basis_points"
                            >
                                {{ rate.name }}
                            </option>
                        </select>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="
                            t('sales.remove_line', {
                                name: line.label || String(index + 1),
                            })
                        "
                        @click="lines.splice(index, 1)"
                        ><X class="size-4"
                    /></Button>
                </li>
            </ul>
            <Button variant="outline" class="h-11" @click="addLine"
                ><Plus class="size-4" />{{ t('purchases.add_line') }}</Button
            >
            <dl class="ml-auto max-w-sm space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>{{ t('money.total_excl_tax') }}</dt>
                    <dd class="tabular-nums">{{ formatXof(totals.excl) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>{{ t('money.total_tax') }}</dt>
                    <dd class="tabular-nums">{{ formatXof(totals.tax) }}</dd>
                </div>
                <div class="flex justify-between font-bold">
                    <dt>{{ t('money.total') }}</dt>
                    <dd class="tabular-nums">
                        {{ formatXof(totals.excl + totals.tax) }}
                    </dd>
                </div>
            </dl>
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
                rows="2"
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
