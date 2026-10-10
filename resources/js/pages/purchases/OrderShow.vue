<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    FileText,
    PackageCheck,
    Pencil,
    X,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import {
    create as invoiceCreate,
    show as invoiceShow,
} from '@/routes/purchases/invoices';
import {
    cancel as cancelRoute,
    confirm as confirmRoute,
    edit as orderEdit,
    index as ordersIndex,
    receive as receiveRoute,
} from '@/routes/purchases/orders';
import { show as receiptShow } from '@/routes/purchases/receipts';

type Status =
    | 'draft'
    | 'confirmed'
    | 'partially_received'
    | 'received'
    | 'closed'
    | 'cancelled';

const props = defineProps<{
    order: {
        id: string;
        number: string | null;
        status: Status;
        supplier: { name: string };
        warehouse: { name: string };
        expected_on: string | null;
        notes: string | null;
        cancel_reason: string | null;
        total_excl_tax: number;
        total_tax: number;
        total: number;
        created_at: string | null;
        lines: {
            id: string;
            sku: string;
            label: string;
            unit: string;
            decimals: boolean;
            quantity: string;
            received: string;
            remaining: string;
            remaining_raw: string;
            unit_cost: number;
            tax_rate: number;
            total: number;
        }[];
        receipts: {
            id: string;
            number: string;
            received_at: string;
            total_excl_tax: number;
        }[];
        invoices: {
            id: string;
            number: string | null;
            status: string;
            total: number;
            supplier_reference: string | null;
        }[];
    };
    can: {
        edit: boolean;
        confirm: boolean;
        receive: boolean;
        cancel: boolean;
        invoice: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('purchases.orders'), href: ordersIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const o = computed(() => props.order);
const processing = ref(false);
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function confirmOrder(): void {
    if (window.confirm(t('purchases.confirm_hint'))) {
        router.post(confirmRoute(o.value.id).url, {}, options);
    }
}

const cancelReason = ref('');
const showCancel = ref(false);

function cancel(): void {
    router.post(
        cancelRoute(o.value.id).url,
        { reason: cancelReason.value || null },
        options,
    );
}

// Réception : une clé par réception (pas de doublon en cas de double envoi).
const receiveKey = crypto.randomUUID();
const quantities = reactive<Record<string, string>>(
    Object.fromEntries(
        props.order.lines.map((l) => [
            l.id,
            l.remaining_raw === '0.000' ? '' : l.remaining,
        ]),
    ),
);
const receipt = reactive({ reference: '', note: '' });

function receive(): void {
    router.post(
        receiveRoute(o.value.id).url,
        {
            idempotency_key: receiveKey,
            supplier_reference: receipt.reference || null,
            note: receipt.note || null,
            quantities,
        },
        options,
    );
}
</script>

<template>
    <Head :title="o.number ?? t('purchases.status.draft')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="ordersIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.back_orders')
            }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    o.number
                        ? t('purchases.order', { number: o.number })
                        : t('purchases.new_order')
                "
                :description="`${o.supplier.name} · ${o.warehouse.name}`"
            />
            <span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{
                t(`purchases.status.${o.status}`)
            }}</span>
        </div>

        <p v-if="o.expected_on" class="text-sm">
            {{ t('purchases.expected_on') }} : {{ formatDate(o.expected_on) }}
        </p>
        <p v-if="o.notes" class="text-sm whitespace-pre-line">{{ o.notes }}</p>
        <p v-if="o.cancel_reason" class="text-sm">
            {{ t('purchases.cancel_reason') }} : {{ o.cancel_reason }}
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="line in o.lines"
                :key="line.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3"
            >
                <span class="min-w-40 flex-1"
                    ><span class="font-medium">{{ line.label }}</span
                    ><span class="block text-xs text-muted-foreground"
                        >{{ line.sku }} · {{ formatXof(line.unit_cost) }} HT ·
                        TVA {{ line.tax_rate / 100 }} %</span
                    ></span
                >
                <span class="text-sm tabular-nums"
                    >{{ line.quantity }} {{ line.unit }}</span
                >
                <span
                    v-if="o.status !== 'draft'"
                    class="text-sm text-muted-foreground tabular-nums"
                    >{{ t('purchases.received') }} {{ line.received }} ·
                    {{ t('purchases.remaining') }} {{ line.remaining }}</span
                >
                <span class="min-w-28 text-right font-semibold tabular-nums">{{
                    formatXof(line.total)
                }}</span>
                <div
                    v-if="can.receive && line.remaining_raw !== '0.000'"
                    class="grid gap-1"
                >
                    <Label :for="`rcv-${line.id}`" class="text-xs">{{
                        t('purchases.received')
                    }}</Label>
                    <Input
                        :id="`rcv-${line.id}`"
                        v-model="quantities[line.id]"
                        :inputmode="line.decimals ? 'decimal' : 'numeric'"
                        class="h-11 w-28 tabular-nums"
                    />
                </div>
            </li>
        </ul>

        <dl class="ml-auto max-w-sm space-y-1 text-sm">
            <div class="flex justify-between">
                <dt>{{ t('money.total_excl_tax') }}</dt>
                <dd class="tabular-nums">{{ formatXof(o.total_excl_tax) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>{{ t('money.total_tax') }}</dt>
                <dd class="tabular-nums">{{ formatXof(o.total_tax) }}</dd>
            </div>
            <div class="flex justify-between border-t pt-1 text-lg font-bold">
                <dt>{{ t('money.total') }}</dt>
                <dd class="tabular-nums">{{ formatXof(o.total) }}</dd>
            </div>
        </dl>

        <InputError :message="errors.business ?? errors.quantities" />

        <section v-if="can.receive" class="space-y-3 rounded-lg border p-4">
            <h2 class="font-semibold">{{ t('purchases.receive_title') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('purchases.receive_hint') }}
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="bl">{{ t('purchases.delivery_note') }}</Label>
                    <Input
                        id="bl"
                        v-model="receipt.reference"
                        maxlength="64"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="rnote">{{ t('purchases.receipt_note') }}</Label>
                    <Input
                        id="rnote"
                        v-model="receipt.note"
                        maxlength="500"
                        class="h-11"
                    />
                </div>
            </div>
            <Button class="h-11" :disabled="processing" @click="receive"
                ><PackageCheck class="size-4" />{{
                    t('purchases.receive')
                }}</Button
            >
        </section>

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.edit" as-child variant="outline" class="h-11"
                ><Link :href="orderEdit(o.id)"
                    ><Pencil class="size-4" />{{ t('purchases.edit') }}</Link
                ></Button
            >
            <Button
                v-if="can.confirm"
                class="h-11"
                :disabled="processing"
                @click="confirmOrder"
                ><CheckCircle2 class="size-4" />{{
                    t('purchases.confirm')
                }}</Button
            >
            <Button v-if="can.invoice" as-child variant="outline" class="h-11"
                ><Link :href="invoiceCreate({ query: { order: o.id } })"
                    ><FileText class="size-4" />{{
                        t('purchases.create_invoice')
                    }}</Link
                ></Button
            >
            <Button
                v-if="can.cancel"
                variant="ghost"
                class="h-11"
                @click="showCancel = !showCancel"
                ><X class="size-4" />{{
                    o.status === 'partially_received'
                        ? t('purchases.close_order')
                        : t('purchases.cancel')
                }}</Button
            >
        </div>

        <section v-if="showCancel" class="space-y-3 rounded-lg border p-4">
            <div class="grid max-w-md gap-1">
                <Label for="reason">{{ t('purchases.cancel_reason') }}</Label>
                <Input
                    id="reason"
                    v-model="cancelReason"
                    maxlength="500"
                    class="h-11"
                />
            </div>
            <Button
                variant="destructive"
                class="h-11"
                :disabled="processing"
                @click="cancel"
                >{{
                    o.status === 'partially_received'
                        ? t('purchases.close_order')
                        : t('purchases.cancel')
                }}</Button
            >
        </section>

        <section v-if="o.receipts.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('purchases.receipts') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li v-for="r in o.receipts" :key="r.id">
                    <Link
                        :href="receiptShow(r.id)"
                        class="flex min-h-11 items-center gap-3 p-3 text-sm hover:bg-accent"
                    >
                        <span class="flex-1 font-medium">{{ r.number }}</span>
                        <span class="text-muted-foreground">{{
                            formatDate(r.received_at)
                        }}</span>
                        <span class="tabular-nums"
                            >{{ formatXof(r.total_excl_tax) }} HT</span
                        >
                    </Link>
                </li>
            </ul>
        </section>

        <section v-if="o.invoices.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('purchases.invoices') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li v-for="i in o.invoices" :key="i.id">
                    <Link
                        :href="invoiceShow(i.id)"
                        class="flex min-h-11 items-center gap-3 p-3 text-sm hover:bg-accent"
                    >
                        <span class="flex-1 font-medium"
                            >{{ i.number ?? t('purchases.status.draft') }}
                            <span
                                v-if="i.supplier_reference"
                                class="text-muted-foreground"
                                >({{ i.supplier_reference }})</span
                            ></span
                        >
                        <span class="tabular-nums">{{
                            formatXof(i.total)
                        }}</span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
