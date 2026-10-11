<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    FileDown,
    Pencil,
    Plus,
    Printer,
    ShoppingCart,
    X,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ShareDocumentDialog from '@/components/ShareDocumentDialog.vue';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof, parseAmount, parseQuantity } from '@/lib/money';
import { create as posCreate } from '@/routes/pos';
import {
    cancel as cancelRoute,
    convert as convertRoute,
    edit as salesEdit,
    index as salesIndex,
    pdf as pdfRoute,
    show as salesShow,
    validate as validateRoute,
} from '@/routes/sales';
import { store as creditStore } from '@/routes/sales/credit_notes';
import { store as paymentStore } from '@/routes/sales/payments';
import { store as refundStore } from '@/routes/sales/refunds';

type DocType = 'quote' | 'order' | 'invoice' | 'credit_note';
type Method = 'cash' | 'wave' | 'orange_money' | 'card' | 'transfer' | 'cheque';
type Line = {
    id: string;
    sku: string;
    label: string;
    unit: string;
    decimals: boolean;
    stockable: boolean;
    quantity: string;
    unit_price: number;
    discount: number;
    tax_rate: number;
    total: number;
    unit_cost: number | null;
    restock: boolean;
    creditable: string;
    creditable_raw: string;
};

const props = defineProps<{
    document: {
        id: string;
        type: DocType;
        number: string | null;
        status: 'draft' | 'validated' | 'converted' | 'cancelled';
        channel: 'pos' | 'backoffice';
        payment_status: 'unpaid' | 'partial' | 'paid' | 'refund_due' | null;
        warehouse: { name: string };
        customer: { id: string | null; name: string | null } | null;
        notes: string | null;
        reason: string | null;
        cancel_reason: string | null;
        negative_stock_reason: string | null;
        issue_date: string | null;
        due_date: string | null;
        valid_until: string | null;
        total_gross: number;
        total_discount: number;
        total_excl_tax: number;
        total_tax: number;
        total: number;
        amount_paid: number;
        amount_credited: number;
        balance: number;
        author: string;
        contact: { phone: string | null; email: string | null };
        created_at: string | null;
        source: { id: string; type: DocType; number: string | null } | null;
        derived: {
            id: string;
            type: DocType;
            number: string | null;
            status: string;
            total: number;
        }[];
        lines: Line[];
        payments: {
            id: string;
            number: string;
            direction: 'in' | 'out';
            method: Method;
            amount: number;
            reference: string | null;
            note: string | null;
            paid_at: string;
            author: string;
        }[];
    };
    sessions: { id: string; register: string; opened_by: string }[];
    fromPos: boolean;
    can: {
        edit: boolean;
        validate: boolean;
        cancel: boolean;
        convert: boolean;
        pay: boolean;
        refund: boolean;
        credit: boolean;
        negative_stock: boolean;
        share: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('sales.title'), href: salesIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const doc = computed(() => props.document);
const processing = ref(false);
const methods: Method[] = [
    'cash',
    'wave',
    'orange_money',
    'card',
    'transfer',
    'cheque',
];
const title = computed(() =>
    doc.value.number
        ? `${t(`sales.types.${doc.value.type}`)} ${doc.value.number}`
        : t('sales.draft_title', { type: t(`sales.types.${doc.value.type}`) }),
);
const isInvoiceLike = computed(
    () => doc.value.type === 'invoice' || doc.value.type === 'credit_note',
);

const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

// --- Validation / conversion / annulation ---
const negativeReason = ref('');
const needsNegativeReason = computed(
    () =>
        props.can.negative_stock &&
        (errors.value.business ?? '').startsWith('Stock insuffisant'),
);

function validateDocument(): void {
    const message =
        doc.value.type === 'invoice'
            ? t('sales.validate_invoice_confirm')
            : t('sales.validate_confirm');

    if (window.confirm(message)) {
        router.post(
            validateRoute(doc.value.id).url,
            { negative_stock_reason: negativeReason.value.trim() || null },
            options,
        );
    }
}

function convert(type: 'order' | 'invoice'): void {
    router.post(convertRoute(doc.value.id).url, { type }, options);
}

const cancelReason = ref('');
const showCancel = ref(false);

function cancel(): void {
    router.post(
        cancelRoute(doc.value.id).url,
        { reason: cancelReason.value.trim() || null },
        options,
    );
}

// --- Paiement ---
const payKey = ref(crypto.randomUUID());
const sessionId = ref(props.sessions[0]?.id ?? '');
const tenders = ref<{ method: Method; amount: string; reference: string }[]>([
    { method: 'cash', amount: '', reference: '' },
]);
const needsSession = computed(() =>
    tenders.value.some((tender) => tender.method === 'cash'),
);

function pay(): void {
    router.post(
        paymentStore(doc.value.id).url,
        {
            idempotency_key: payKey.value,
            cash_session_id: needsSession.value
                ? sessionId.value || null
                : null,
            tenders: tenders.value
                .map((tender) => ({
                    method: tender.method,
                    amount:
                        tenders.value.length === 1 &&
                        tender.amount.trim() === ''
                            ? doc.value.balance
                            : parseAmount(tender.amount),
                    reference: tender.reference.trim() || null,
                }))
                .filter((tender) => tender.amount > 0),
        },
        {
            ...options,
            onSuccess: () => {
                payKey.value = crypto.randomUUID();
                tenders.value = [{ method: 'cash', amount: '', reference: '' }];
            },
        },
    );
}

// --- Remboursement ---
const refund = reactive({
    key: crypto.randomUUID(),
    method: 'cash' as Method,
    amount: '',
    reason: '',
});

function submitRefund(): void {
    router.post(
        refundStore(doc.value.id).url,
        {
            idempotency_key: refund.key,
            method: refund.method,
            amount: parseAmount(refund.amount) || -doc.value.balance,
            cash_session_id:
                refund.method === 'cash' ? sessionId.value || null : null,
            reason: refund.reason,
        },
        options,
    );
}

// --- Avoir ---
const showCredit = ref(false);
const creditKey = crypto.randomUUID();
const creditReason = ref('');
const credit = reactive<Record<string, { quantity: string; restock: boolean }>>(
    Object.fromEntries(
        props.document.lines.map((l) => [
            l.id,
            { quantity: '', restock: l.stockable },
        ]),
    ),
);
const creditLines = computed(() =>
    Object.entries(credit)
        .filter(([, v]) => (parseQuantity(v.quantity) ?? 0n) > 0n)
        .map(([id, v]) => ({
            line_id: id,
            quantity: v.quantity,
            restock: v.restock,
        })),
);

function submitCredit(): void {
    if (window.confirm(t('sales.credit_confirm'))) {
        router.post(
            creditStore(doc.value.id).url,
            {
                idempotency_key: creditKey,
                reason: creditReason.value,
                lines: creditLines.value,
            },
            options,
        );
    }
}

const statusClass = computed(
    () =>
        ({
            draft: 'bg-muted text-foreground',
            validated: 'bg-success-soft text-success-soft-foreground',
            converted: 'bg-info-soft text-info-soft-foreground',
            cancelled: 'bg-muted text-muted-foreground line-through',
        })[doc.value.status],
);
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="salesIndex({ query: { type: doc.type } })"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{ t('sales.back') }}
        </Link>

        <!-- Vente comptoir terminée : reçu et vente suivante -->
        <div
            v-if="fromPos"
            class="flex flex-wrap items-center gap-3 rounded-lg border border-success-border bg-success-soft p-4"
            role="status"
        >
            <CheckCircle2 class="size-6 text-success" aria-hidden="true" />
            <p class="flex-1 font-medium">
                {{ title }} · {{ formatXof(doc.total) }}
            </p>
            <Button as-child class="h-11"
                ><a
                    :href="
                        pdfRoute(doc.id, { query: { format: 'receipt' } }).url
                    "
                    target="_blank"
                    rel="noopener"
                    ><Printer class="size-4" />{{ t('sales.receipt') }}</a
                ></Button
            >
            <ShareDocumentDialog
                v-if="can.share && doc.number"
                :document-id="doc.id"
                :number="doc.number"
                :type-label="t(`sales.types.${doc.type}`)"
                :total="doc.total"
                :receipt="isInvoiceLike"
                :contact="doc.contact"
            />
            <Button as-child variant="outline" class="h-11"
                ><Link :href="posCreate()"
                    ><ShoppingCart class="size-4" />{{
                        t('pos.new_sale')
                    }}</Link
                ></Button
            >
        </div>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="title"
                :description="doc.customer?.name ?? t('sales.walk_in')"
            />
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded px-2 py-1 text-xs font-medium"
                    :class="statusClass"
                    >{{ t(`sales.status.${doc.status}`) }}</span
                >
                <span
                    v-if="doc.payment_status && doc.status === 'validated'"
                    class="rounded bg-muted px-2 py-1 text-xs font-medium"
                    >{{ t(`sales.payment_status.${doc.payment_status}`) }}</span
                >
            </div>
        </div>

        <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div>
                <dt class="inline text-muted-foreground">
                    {{ t('sales.warehouse') }} :
                </dt>
                <dd class="inline">{{ doc.warehouse.name }}</dd>
            </div>
            <div v-if="doc.issue_date">
                <dt class="inline text-muted-foreground">
                    {{ t('sales.issue_date') }} :
                </dt>
                <dd class="inline">{{ formatDate(doc.issue_date) }}</dd>
            </div>
            <div v-if="doc.due_date && doc.type === 'invoice'">
                <dt class="inline text-muted-foreground">
                    {{ t('sales.due_date') }} :
                </dt>
                <dd class="inline">{{ formatDate(doc.due_date) }}</dd>
            </div>
            <div v-if="doc.valid_until && doc.type === 'quote'">
                <dt class="inline text-muted-foreground">
                    {{ t('sales.valid_until') }} :
                </dt>
                <dd class="inline">{{ formatDate(doc.valid_until) }}</dd>
            </div>
            <div class="text-muted-foreground">
                {{ t('sales.created_by', { name: doc.author }) }}
            </div>
            <div v-if="doc.source">
                <Link
                    :href="salesShow(doc.source.id)"
                    class="underline underline-offset-4"
                    >{{
                        t('sales.source', {
                            type: t(`sales.types.${doc.source.type}`),
                            number: doc.source.number ?? '',
                        })
                    }}</Link
                >
            </div>
        </dl>

        <p v-if="doc.reason" class="text-sm">
            <span class="text-muted-foreground">{{ t('sales.reason') }} :</span>
            {{ doc.reason }}
        </p>
        <p v-if="doc.cancel_reason" class="text-sm">
            <span class="text-muted-foreground"
                >{{ t('sales.cancel_reason') }} :</span
            >
            {{ doc.cancel_reason }}
        </p>
        <p
            v-if="doc.negative_stock_reason"
            class="rounded-md bg-warning-soft p-2 text-sm"
        >
            {{
                t('sales.negative_stock_used', {
                    reason: doc.negative_stock_reason,
                })
            }}
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="line in doc.lines"
                :key="line.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3"
            >
                <span class="min-w-40 flex-1"
                    ><span class="font-medium">{{ line.label }}</span
                    ><span class="block text-xs text-muted-foreground"
                        >{{ line.sku }} · TVA
                        {{ line.tax_rate / 100 }} %<template
                            v-if="line.unit_cost !== null"
                        >
                            · {{ t('sales.cost') }}
                            {{ formatXof(line.unit_cost) }}</template
                        ></span
                    ></span
                >
                <span class="text-sm tabular-nums"
                    >{{ line.quantity }} {{ line.unit }} ×
                    {{ formatXof(line.unit_price) }}</span
                >
                <span
                    v-if="line.discount > 0"
                    class="text-sm text-muted-foreground tabular-nums"
                    >− {{ formatXof(line.discount) }}</span
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
                    {{ formatXof(doc.total_excl_tax) }}
                </dd>
            </div>
            <div class="flex justify-between">
                <dt>{{ t('money.total_tax') }}</dt>
                <dd class="tabular-nums">{{ formatXof(doc.total_tax) }}</dd>
            </div>
            <div
                v-if="doc.total_discount > 0"
                class="flex justify-between text-muted-foreground"
            >
                <dt>{{ t('sales.discount') }}</dt>
                <dd class="tabular-nums">
                    − {{ formatXof(doc.total_discount) }}
                </dd>
            </div>
            <div class="flex justify-between border-t pt-1 text-lg font-bold">
                <dt>{{ t('money.total') }}</dt>
                <dd class="tabular-nums">{{ formatXof(doc.total) }}</dd>
            </div>
            <template
                v-if="doc.type === 'invoice' && doc.status === 'validated'"
            >
                <div v-if="doc.amount_credited" class="flex justify-between">
                    <dt>{{ t('money.credited') }}</dt>
                    <dd class="tabular-nums">
                        − {{ formatXof(doc.amount_credited) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>{{ t('money.paid') }}</dt>
                    <dd class="tabular-nums">
                        {{ formatXof(doc.amount_paid) }}
                    </dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt>{{ t('money.balance') }}</dt>
                    <dd class="tabular-nums">{{ formatXof(doc.balance) }}</dd>
                </div>
            </template>
        </dl>

        <p v-if="doc.notes" class="text-sm whitespace-pre-line">
            {{ doc.notes }}
        </p>

        <InputError :message="errors.business" />

        <div v-if="needsNegativeReason" class="grid max-w-md gap-1">
            <Label for="negative">{{ t('sales.negative_reason') }}</Label>
            <Input
                id="negative"
                v-model="negativeReason"
                maxlength="500"
                class="h-11"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('sales.negative_hint') }}
            </p>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap gap-2">
            <Button v-if="can.edit" as-child variant="outline" class="h-11"
                ><Link :href="salesEdit(doc.id)"
                    ><Pencil class="size-4" />{{ t('sales.edit') }}</Link
                ></Button
            >
            <Button
                v-if="can.validate"
                class="h-11"
                :disabled="processing"
                @click="validateDocument"
                ><CheckCircle2 class="size-4" />{{
                    t('sales.validate')
                }}</Button
            >
            <template v-if="can.convert">
                <Button
                    v-if="doc.type === 'quote'"
                    variant="outline"
                    class="h-11"
                    :disabled="processing"
                    @click="convert('order')"
                    >{{ t('sales.convert_order') }}</Button
                >
                <Button
                    class="h-11"
                    :disabled="processing"
                    @click="convert('invoice')"
                    >{{ t('sales.convert_invoice') }}</Button
                >
            </template>
            <Button
                v-if="doc.number && doc.status !== 'cancelled'"
                as-child
                variant="outline"
                class="h-11"
                ><a :href="pdfRoute(doc.id).url" target="_blank" rel="noopener"
                    ><FileDown class="size-4" />{{ t('sales.pdf') }}</a
                ></Button
            >
            <ShareDocumentDialog
                v-if="can.share && doc.number && !fromPos"
                :document-id="doc.id"
                :number="doc.number"
                :type-label="t(`sales.types.${doc.type}`)"
                :total="doc.total"
                :receipt="isInvoiceLike"
                :contact="doc.contact"
            />
            <Button
                v-if="isInvoiceLike && doc.number && !fromPos"
                as-child
                variant="outline"
                class="h-11"
                ><a
                    :href="
                        pdfRoute(doc.id, { query: { format: 'receipt' } }).url
                    "
                    target="_blank"
                    rel="noopener"
                    ><Printer class="size-4" />{{ t('sales.receipt') }}</a
                ></Button
            >
            <Button
                v-if="can.credit"
                variant="outline"
                class="h-11"
                @click="showCredit = !showCredit"
                >{{ t('sales.credit_note') }}</Button
            >
            <Button
                v-if="can.cancel"
                variant="ghost"
                class="h-11"
                @click="showCancel = !showCancel"
                ><X class="size-4" />{{ t('sales.cancel') }}</Button
            >
        </div>

        <section
            v-if="showCancel && can.cancel"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="grid gap-1">
                <Label for="cancel-reason">{{
                    t('sales.cancel_reason')
                }}</Label>
                <Input
                    id="cancel-reason"
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
                >{{ t('sales.cancel') }}</Button
            >
        </section>

        <!-- Documents liés -->
        <section v-if="doc.derived.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('sales.derived') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li v-for="d in doc.derived" :key="d.id">
                    <Link
                        :href="salesShow(d.id)"
                        class="flex min-h-11 items-center gap-3 p-3 text-sm hover:bg-accent"
                    >
                        <span class="flex-1"
                            >{{ t(`sales.types.${d.type}`) }}
                            {{ d.number ?? t('sales.status.draft') }}</span
                        >
                        <span class="tabular-nums">{{
                            formatXof(d.total)
                        }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Paiements -->
        <section
            v-if="doc.type === 'invoice' && doc.status === 'validated'"
            class="space-y-3"
        >
            <h2 class="font-semibold">{{ t('sales.payments') }}</h2>
            <ul v-if="doc.payments.length" class="divide-y rounded-lg border">
                <li
                    v-for="p in doc.payments"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm"
                >
                    <span class="font-medium">{{ p.number }}</span>
                    <span class="flex-1"
                        >{{ t(`methods.${p.method}`)
                        }}<template v-if="p.reference">
                            · {{ p.reference }}</template
                        ><template v-if="p.note">
                            · {{ p.note }}</template
                        ></span
                    >
                    <span class="text-muted-foreground"
                        >{{ formatDate(p.paid_at) }} · {{ p.author }}</span
                    >
                    <span class="font-semibold tabular-nums"
                        >{{ p.direction === 'out' ? '− ' : ''
                        }}{{ formatXof(p.amount) }}</span
                    >
                </li>
            </ul>

            <div v-if="can.pay" class="space-y-3 rounded-lg border p-4">
                <h3 class="font-medium">{{ t('sales.add_payment') }}</h3>
                <div
                    v-for="(tender, index) in tenders"
                    :key="index"
                    class="grid gap-2 sm:grid-cols-[10rem_10rem_1fr_auto]"
                >
                    <select
                        v-model="tender.method"
                        :class="selectClass"
                        :aria-label="t('pos.payment')"
                    >
                        <option v-for="m in methods" :key="m" :value="m">
                            {{ t(`methods.${m}`) }}
                        </option>
                    </select>
                    <Input
                        v-model="tender.amount"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                        :placeholder="
                            tenders.length === 1 ? String(doc.balance) : ''
                        "
                        :aria-label="t('pos.amount')"
                    />
                    <Input
                        v-if="tender.method !== 'cash'"
                        v-model="tender.reference"
                        maxlength="100"
                        class="h-11"
                        :placeholder="t('sales.reference')"
                    />
                    <span v-else />
                    <Button
                        v-if="index > 0"
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="t('pos.remove_method')"
                        @click="tenders.splice(index, 1)"
                        ><X class="size-4"
                    /></Button>
                </div>
                <Button
                    variant="outline"
                    class="h-11"
                    @click="
                        tenders.push({
                            method: 'wave',
                            amount: '',
                            reference: '',
                        })
                    "
                    ><Plus class="size-4" />{{ t('pos.add_method') }}</Button
                >
                <div v-if="needsSession" class="grid max-w-sm gap-1">
                    <Label for="pay-session">{{ t('sales.session') }}</Label>
                    <select
                        v-if="sessions.length"
                        id="pay-session"
                        v-model="sessionId"
                        :class="selectClass"
                    >
                        <option v-for="s in sessions" :key="s.id" :value="s.id">
                            {{ s.register }} — {{ s.opened_by }}
                        </option>
                    </select>
                    <p v-else class="text-sm text-destructive">
                        {{ t('sales.no_session') }}
                    </p>
                </div>
                <InputError
                    :message="errors.tenders ?? errors.cash_session_id"
                />
                <Button class="h-11" :disabled="processing" @click="pay">{{
                    t('sales.pay')
                }}</Button>
            </div>

            <div v-if="can.refund" class="space-y-3 rounded-lg border p-4">
                <h3 class="font-medium">{{ t('sales.refund_title') }}</h3>
                <div class="grid gap-2 sm:grid-cols-3">
                    <select
                        v-model="refund.method"
                        :class="selectClass"
                        :aria-label="t('pos.payment')"
                    >
                        <option v-for="m in methods" :key="m" :value="m">
                            {{ t(`methods.${m}`) }}
                        </option>
                    </select>
                    <Input
                        v-model="refund.amount"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                        :placeholder="String(-doc.balance)"
                        :aria-label="t('pos.amount')"
                    />
                    <Input
                        v-model="refund.reason"
                        maxlength="500"
                        class="h-11"
                        :placeholder="t('sales.reason')"
                        :aria-label="t('sales.reason')"
                    />
                </div>
                <select
                    v-if="refund.method === 'cash' && sessions.length"
                    v-model="sessionId"
                    :class="selectClass"
                    :aria-label="t('sales.session')"
                >
                    <option v-for="s in sessions" :key="s.id" :value="s.id">
                        {{ s.register }} — {{ s.opened_by }}
                    </option>
                </select>
                <InputError :message="errors.reason ?? errors.amount" />
                <Button
                    class="h-11"
                    :disabled="processing"
                    @click="submitRefund"
                    >{{ t('sales.refund') }}</Button
                >
            </div>
        </section>

        <!-- Avoir -->
        <section
            v-if="showCredit && can.credit"
            class="space-y-3 rounded-lg border p-4"
        >
            <h2 class="font-semibold">{{ t('sales.credit_title') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('sales.credit_hint') }}
            </p>
            <ul class="divide-y">
                <li
                    v-for="line in doc.lines"
                    :key="line.id"
                    class="flex flex-wrap items-end gap-3 py-2"
                >
                    <span class="min-w-40 flex-1 text-sm"
                        >{{ line.label }}
                        <span class="block text-xs text-muted-foreground">{{
                            t('sales.creditable', { quantity: line.creditable })
                        }}</span></span
                    >
                    <div class="grid gap-1">
                        <Label :for="`cr-${line.id}`" class="text-xs">{{
                            t('sales.credit_quantity')
                        }}</Label>
                        <Input
                            :id="`cr-${line.id}`"
                            v-model="credit[line.id].quantity"
                            :inputmode="line.decimals ? 'decimal' : 'numeric'"
                            class="h-11 w-24 tabular-nums"
                            :disabled="
                                (parseQuantity(line.creditable_raw) ?? 0n) <= 0n
                            "
                        />
                    </div>
                    <label
                        v-if="line.stockable"
                        class="inline-flex min-h-11 items-center gap-2 text-sm"
                    >
                        <input
                            v-model="credit[line.id].restock"
                            type="checkbox"
                            class="size-5"
                        />{{ t('sales.restock') }}
                    </label>
                </li>
            </ul>
            <div class="grid max-w-md gap-1">
                <Label for="credit-reason">{{ t('sales.reason') }}</Label>
                <Input
                    id="credit-reason"
                    v-model="creditReason"
                    maxlength="500"
                    class="h-11"
                />
            </div>
            <InputError :message="errors.reason ?? errors.lines" />
            <Button
                class="h-11"
                :disabled="processing || creditLines.length === 0"
                @click="submitCredit"
                >{{ t('sales.credit_submit') }}</Button
            >
        </section>
    </div>
</template>
