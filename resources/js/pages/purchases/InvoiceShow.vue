<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Pencil, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof, parseAmount } from '@/lib/money';
import {
    cancel as cancelRoute,
    edit as invoiceEdit,
    index as invoicesIndex,
    validate as validateRoute,
} from '@/routes/purchases/invoices';
import { store as paymentStore } from '@/routes/purchases/invoices/payments';
import { show as orderShow } from '@/routes/purchases/orders';
import { show as receiptShow } from '@/routes/purchases/receipts';

type Method = 'cash' | 'wave' | 'orange_money' | 'card' | 'transfer' | 'cheque';

const props = defineProps<{
    invoice: {
        id: string;
        number: string | null;
        supplier_reference: string | null;
        status: 'draft' | 'validated' | 'cancelled';
        payment_status: 'unpaid' | 'partial' | 'paid' | 'refund_due' | null;
        supplier: { name: string };
        warehouse: { name: string };
        issue_date: string | null;
        due_date: string | null;
        notes: string | null;
        total_excl_tax: number;
        total_tax: number;
        total: number;
        amount_paid: number;
        amount_credited: number;
        balance: number;
        order: { id: string; number: string | null } | null;
        receipt: { id: string; number: string } | null;
        lines: {
            id: string;
            label: string;
            quantity: string;
            unit_cost: number;
            tax_rate: number;
            total: number;
        }[];
        payments: {
            id: string;
            number: string;
            method: Method;
            amount: number;
            reference: string | null;
            paid_at: string;
            author: string;
        }[];
        returns: {
            id: string;
            number: string;
            total: number;
            reason: string;
        }[];
    };
    sessions: { id: string; register: string; opened_by: string }[];
    can: { edit: boolean; validate: boolean; cancel: boolean; pay: boolean };
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
const inv = computed(() => props.invoice);
const processing = ref(false);
const methods: Method[] = [
    'transfer',
    'cash',
    'wave',
    'orange_money',
    'cheque',
    'card',
];
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function validateInvoice(): void {
    if (window.confirm(t('purchases.validate_confirm'))) {
        router.post(validateRoute(inv.value.id).url, {}, options);
    }
}

function cancel(): void {
    router.post(cancelRoute(inv.value.id).url, {}, options);
}

const payment = reactive({
    key: crypto.randomUUID(),
    method: 'transfer' as Method,
    amount: '',
    reference: '',
    session: props.sessions[0]?.id ?? '',
});

function pay(): void {
    router.post(
        paymentStore(inv.value.id).url,
        {
            idempotency_key: payment.key,
            method: payment.method,
            amount: parseAmount(payment.amount) || inv.value.balance,
            reference: payment.reference || null,
            cash_session_id:
                payment.method === 'cash' ? payment.session || null : null,
        },
        {
            ...options,
            onSuccess: () => {
                payment.key = crypto.randomUUID();
                payment.amount = '';
                payment.reference = '';
            },
        },
    );
}
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Head :title="inv.number ?? t('purchases.status.draft')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="invoicesIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.back_invoices')
            }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    inv.number
                        ? t('purchases.invoice', { number: inv.number })
                        : t('purchases.new_invoice')
                "
                :description="`${inv.supplier.name} · ${inv.warehouse.name}${inv.supplier_reference ? ' · ' + inv.supplier_reference : ''}`"
            />
            <span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{
                inv.status === 'validated' && inv.payment_status
                    ? t(`sales.payment_status.${inv.payment_status}`)
                    : t(`purchases.status.${inv.status}`)
            }}</span>
        </div>

        <p class="flex flex-wrap gap-x-4 text-sm">
            <span v-if="inv.issue_date"
                >{{ t('purchases.issue_date') }} :
                {{ formatDate(inv.issue_date) }}</span
            >
            <span v-if="inv.due_date"
                >{{ t('purchases.due_date') }} :
                {{ formatDate(inv.due_date) }}</span
            >
            <Link
                v-if="inv.order"
                :href="orderShow(inv.order.id)"
                class="underline underline-offset-4"
                >{{
                    t('purchases.order', { number: inv.order.number ?? '' })
                }}</Link
            >
            <Link
                v-if="inv.receipt"
                :href="receiptShow(inv.receipt.id)"
                class="underline underline-offset-4"
                >{{
                    t('purchases.receipt', { number: inv.receipt.number })
                }}</Link
            >
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="line in inv.lines"
                :key="line.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3"
            >
                <span class="min-w-40 flex-1 font-medium">{{
                    line.label
                }}</span>
                <span class="text-sm tabular-nums"
                    >{{ line.quantity }} × {{ formatXof(line.unit_cost) }} HT ·
                    TVA {{ line.tax_rate / 100 }} %</span
                >
                <span class="min-w-28 text-right font-semibold tabular-nums">{{
                    formatXof(line.total)
                }}</span>
            </li>
        </ul>

        <dl class="ml-auto max-w-sm space-y-1 text-sm">
            <div class="flex justify-between">
                <dt>{{ t('money.total_excl_tax') }}</dt>
                <dd class="tabular-nums">
                    {{ formatXof(inv.total_excl_tax) }}
                </dd>
            </div>
            <div class="flex justify-between">
                <dt>{{ t('money.total_tax') }}</dt>
                <dd class="tabular-nums">{{ formatXof(inv.total_tax) }}</dd>
            </div>
            <div class="flex justify-between border-t pt-1 text-lg font-bold">
                <dt>{{ t('money.total') }}</dt>
                <dd class="tabular-nums">{{ formatXof(inv.total) }}</dd>
            </div>
            <template v-if="inv.status === 'validated'">
                <div v-if="inv.amount_credited" class="flex justify-between">
                    <dt>{{ t('money.credited') }}</dt>
                    <dd class="tabular-nums">
                        − {{ formatXof(inv.amount_credited) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>{{ t('money.paid') }}</dt>
                    <dd class="tabular-nums">
                        {{ formatXof(inv.amount_paid) }}
                    </dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt>{{ t('money.balance') }}</dt>
                    <dd class="tabular-nums">{{ formatXof(inv.balance) }}</dd>
                </div>
            </template>
        </dl>

        <p v-if="inv.notes" class="text-sm whitespace-pre-line">
            {{ inv.notes }}
        </p>
        <InputError :message="errors.business" />

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.edit" as-child variant="outline" class="h-11"
                ><Link :href="invoiceEdit(inv.id)"
                    ><Pencil class="size-4" />{{ t('purchases.edit') }}</Link
                ></Button
            >
            <Button
                v-if="can.validate"
                class="h-11"
                :disabled="processing"
                @click="validateInvoice"
                ><CheckCircle2 class="size-4" />{{
                    t('purchases.validate')
                }}</Button
            >
            <Button
                v-if="can.cancel"
                variant="ghost"
                class="h-11"
                :disabled="processing"
                @click="cancel"
                ><X class="size-4" />{{ t('purchases.cancel') }}</Button
            >
        </div>

        <section v-if="inv.status === 'validated'" class="space-y-3">
            <h2 class="font-semibold">{{ t('purchases.payments') }}</h2>
            <ul v-if="inv.payments.length" class="divide-y rounded-lg border">
                <li
                    v-for="p in inv.payments"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm"
                >
                    <span class="font-medium">{{ p.number }}</span>
                    <span class="flex-1"
                        >{{ t(`methods.${p.method}`)
                        }}<template v-if="p.reference">
                            · {{ p.reference }}</template
                        ></span
                    >
                    <span class="text-muted-foreground"
                        >{{ formatDate(p.paid_at) }} · {{ p.author }}</span
                    >
                    <span class="font-semibold tabular-nums">{{
                        formatXof(p.amount)
                    }}</span>
                </li>
            </ul>
            <ul v-if="inv.returns.length" class="divide-y rounded-lg border">
                <li
                    v-for="r in inv.returns"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-3 p-3 text-sm"
                >
                    <span class="font-medium">{{ r.number }}</span>
                    <span class="flex-1">{{ r.reason }}</span>
                    <span class="tabular-nums">− {{ formatXof(r.total) }}</span>
                </li>
            </ul>

            <div v-if="can.pay" class="space-y-3 rounded-lg border p-4">
                <h3 class="font-medium">{{ t('purchases.pay_title') }}</h3>
                <div class="grid gap-2 sm:grid-cols-3">
                    <select
                        v-model="payment.method"
                        :class="selectClass"
                        :aria-label="t('pos.payment')"
                    >
                        <option v-for="m in methods" :key="m" :value="m">
                            {{ t(`methods.${m}`) }}
                        </option>
                    </select>
                    <Input
                        v-model="payment.amount"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                        :placeholder="String(inv.balance)"
                        :aria-label="t('pos.amount')"
                    />
                    <Input
                        v-if="payment.method !== 'cash'"
                        v-model="payment.reference"
                        maxlength="100"
                        class="h-11"
                        :placeholder="t('sales.reference')"
                    />
                    <template v-else>
                        <select
                            v-if="sessions.length"
                            v-model="payment.session"
                            :class="selectClass"
                            :aria-label="t('sales.session')"
                        >
                            <option
                                v-for="s in sessions"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.register }} — {{ s.opened_by }}
                            </option>
                        </select>
                        <p v-else class="text-sm text-destructive">
                            {{ t('sales.no_session') }}
                        </p>
                    </template>
                </div>
                <InputError :message="errors.amount ?? errors.method" />
                <Button class="h-11" :disabled="processing" @click="pay">{{
                    t('purchases.pay')
                }}</Button>
            </div>
        </section>
    </div>
</template>
