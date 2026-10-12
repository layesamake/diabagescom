<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
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
    index as billingIndex,
    exportMethod as exportRoute,
} from '@/routes/platform/billing';
import {
    show as creditNoteRoute,
    store as creditNoteStore,
} from '@/routes/platform/billing/credit-notes';
import {
    receipt as receiptRoute,
    voidMethod as voidRoute,
} from '@/routes/platform/billing/invoices';
import {
    confirm as confirmRoute,
    proof as proofRoute,
    reject as rejectRoute,
} from '@/routes/platform/billing/payments';
import { askText } from '@/composables/useConfirm';

type Invoice = {
    id: string;
    number: string;
    tenant: string;
    plan: string;
    cycle: 'monthly' | 'yearly';
    total: number;
    status: 'open' | 'paid' | 'void';
    due_on: string;
    paid_at: string | null;
    credit_notes: { id: string; number: string; amount: number }[];
    credited: number;
};

const props = defineProps<{
    invoices: Invoice[];
    payments: {
        id: string;
        tenant: string;
        provider: string;
        status: string;
        amount: number;
        reference: string;
        reason: string | null;
        declared_reference: string | null;
        declared_at: string | null;
        has_proof: boolean;
        created_at: string | null;
    }[];
    events: {
        id: string;
        provider: string;
        event: string;
        type: string | null;
        signature_valid: boolean;
        status: string;
        error: string | null;
        created_at: string | null;
    }[];
    waveNumber: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('billing.console_title'), href: billingIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
// La référence déclarée par le client pré-remplit le justificatif à valider.
const proofs = reactive<Record<string, string>>(
    Object.fromEntries(
        props.payments.map((p) => [p.id, p.declared_reference ?? '']),
    ),
);
const today = new Date().toISOString().slice(0, 10);
const exportRange = reactive({ from: `${today.slice(0, 8)}01`, to: today });
const exportUrl = computed(
    () =>
        exportRoute({ query: { from: exportRange.from, to: exportRange.to } })
            .url,
);
const crediting = ref<string | null>(null);
const credit = reactive({ amount: 0, reason: '' });
const processing = ref(false);
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function confirmTransfer(id: string): void {
    router.post(
        confirmRoute(id).url,
        { proof_reference: proofs[id] ?? '' },
        options,
    );
}

async function rejectPayment(id: string): Promise<void> {
    const reason = await askText({
        title: t('billing.reject_title'),
        input: { label: t('billing.reject_reason'), required: true },
        confirmLabel: t('billing.reject'),
        tone: 'danger',
    });

    if (reason) {
        router.post(rejectRoute(id).url, { reason }, options);
    }
}

async function voidInvoice(id: string): Promise<void> {
    const reason = await askText({
        title: t('confirm.void_title'),
        input: { label: t('billing.void_reason'), required: true },
        confirmLabel: t('confirm.void'),
        tone: 'danger',
    });

    if (reason) {
        router.post(voidRoute(id).url, { reason }, options);
    }
}

function startCredit(invoice: Invoice): void {
    crediting.value = invoice.id;
    credit.amount = invoice.total - invoice.credited;
    credit.reason = '';
}

function issueCredit(id: string): void {
    router.post(
        creditNoteStore(id).url,
        { amount: credit.amount, reason: credit.reason },
        { ...options, onSuccess: () => (crediting.value = null) },
    );
}
</script>

<template>
    <Head :title="t('billing.console_title')" />

    <div class="space-y-8 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading :title="t('billing.console_title')" />
            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1">
                    <Label for="export-from">{{
                        t('billing.export_from')
                    }}</Label>
                    <Input
                        id="export-from"
                        v-model="exportRange.from"
                        type="date"
                        class="h-10"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="export-to">{{ t('billing.export_to') }}</Label>
                    <Input
                        id="export-to"
                        v-model="exportRange.to"
                        type="date"
                        class="h-10"
                    />
                </div>
                <Button as-child variant="outline" class="h-10">
                    <a :href="exportUrl"
                        ><Download class="size-4" />{{ t('billing.export') }}</a
                    >
                </Button>
            </div>
        </div>
        <InputError
            :message="
                errors.business ??
                errors.proof_reference ??
                errors.reason ??
                errors.amount
            "
        />

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.console_payments') }}</h2>
            <p
                v-if="payments.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li v-for="p in payments" :key="p.id" class="space-y-2 p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="min-w-40 font-medium">{{ p.tenant }}</span>
                        <span class="rounded bg-muted px-2 py-0.5 text-xs">{{
                            t(
                                `billing.providers.${p.provider}` as 'billing.providers.transfer',
                            )
                        }}</span>
                        <span class="font-mono text-xs">{{ p.reference }}</span>
                        <span class="tabular-nums">{{
                            formatXof(p.amount)
                        }}</span>
                        <span class="flex-1 text-muted-foreground"
                            >{{ p.status
                            }}<template v-if="p.reason">
                                · {{ p.reason }}</template
                            ></span
                        >
                    </div>
                    <div
                        v-if="p.status === 'pending_manual_review'"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <span
                            v-if="p.declared_at"
                            class="font-medium text-success-soft-foreground"
                            >{{
                                t('billing.declared_by_client', {
                                    date: formatDate(p.declared_at),
                                    reference: p.declared_reference ?? '',
                                })
                            }}</span
                        >
                        <span v-else class="text-muted-foreground">{{
                            t('billing.not_declared')
                        }}</span>
                        <a
                            v-if="p.has_proof"
                            :href="proofRoute(p.id).url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex min-h-10 items-center text-primary underline-offset-4 hover:underline"
                            >{{ t('billing.view_proof') }}</a
                        >
                        <Input
                            v-model="proofs[p.id]"
                            class="h-10 w-48"
                            :placeholder="t('billing.proof')"
                            :aria-label="t('billing.proof')"
                        />
                        <Button
                            class="h-10"
                            :disabled="processing"
                            @click="confirmTransfer(p.id)"
                            >{{ t('billing.confirm_transfer') }}</Button
                        >
                        <Button
                            variant="ghost"
                            class="h-10"
                            :disabled="processing"
                            @click="rejectPayment(p.id)"
                            >{{ t('billing.reject') }}</Button
                        >
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.console_invoices') }}</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li v-for="i in invoices" :key="i.id" class="space-y-2 p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="min-w-36 font-medium">{{ i.number }}</span>
                        <span class="min-w-40">{{ i.tenant }}</span>
                        <span class="flex-1 text-muted-foreground"
                            >{{ i.plan }} ·
                            {{ t(`billing.cycles.${i.cycle}`) }} ·
                            {{ formatDate(i.due_on) }}</span
                        >
                        <span class="tabular-nums">{{
                            formatXof(i.total)
                        }}</span>
                        <span class="rounded bg-muted px-2 py-0.5 text-xs">{{
                            t(`billing.status.${i.status}`)
                        }}</span>
                        <Button
                            v-if="i.status === 'open'"
                            variant="ghost"
                            size="sm"
                            class="h-10"
                            @click="voidInvoice(i.id)"
                            >{{ t('billing.void') }}</Button
                        >
                        <template v-if="i.status === 'paid'">
                            <a
                                :href="receiptRoute(i.id).url"
                                class="inline-flex min-h-10 items-center gap-1 text-primary underline-offset-4 hover:underline"
                                ><Download class="size-4" />{{
                                    t('billing.receipt')
                                }}</a
                            >
                            <Button
                                v-if="i.credited < i.total"
                                variant="ghost"
                                size="sm"
                                class="h-10"
                                @click="startCredit(i)"
                                >{{ t('billing.credit_note') }}</Button
                            >
                        </template>
                    </div>
                    <div
                        v-if="i.credit_notes.length"
                        class="flex flex-wrap gap-3 text-muted-foreground"
                    >
                        <span>{{
                            t('billing.credited', {
                                amount: formatXof(i.credited),
                            })
                        }}</span>
                        <a
                            v-for="note in i.credit_notes"
                            :key="note.id"
                            :href="creditNoteRoute(note.id).url"
                            class="inline-flex min-h-10 items-center gap-1 text-primary underline-offset-4 hover:underline"
                            ><Download class="size-4" />{{
                                t('billing.credit_note_label', {
                                    number: note.number,
                                    amount: formatXof(note.amount),
                                })
                            }}</a
                        >
                    </div>
                    <form
                        v-if="crediting === i.id"
                        class="flex flex-wrap items-end gap-2 rounded-md bg-muted p-3"
                        @submit.prevent="issueCredit(i.id)"
                    >
                        <div class="grid gap-1">
                            <Label :for="`credit-amount-${i.id}`">{{
                                t('billing.credit_amount')
                            }}</Label>
                            <Input
                                :id="`credit-amount-${i.id}`"
                                v-model.number="credit.amount"
                                type="number"
                                min="1"
                                :max="i.total - i.credited"
                                required
                                class="h-10 w-36 bg-background"
                            />
                        </div>
                        <div class="grid flex-1 gap-1">
                            <Label :for="`credit-reason-${i.id}`">{{
                                t('billing.credit_reason')
                            }}</Label>
                            <Input
                                :id="`credit-reason-${i.id}`"
                                v-model="credit.reason"
                                required
                                minlength="5"
                                maxlength="500"
                                class="h-10 min-w-56 bg-background"
                            />
                        </div>
                        <Button
                            type="submit"
                            class="h-10"
                            :disabled="processing"
                            >{{ t('billing.credit_submit') }}</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            class="h-10"
                            @click="crediting = null"
                            >{{ t('billing.cancel') }}</Button
                        >
                    </form>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.console_events') }}</h2>
            <p v-if="events.length === 0" class="text-sm text-muted-foreground">
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="e in events"
                    :key="e.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-24 font-medium">{{ e.provider }}</span>
                    <span class="font-mono text-xs">{{ e.event }}</span>
                    <span class="flex-1"
                        >{{ e.type }} · {{ e.status
                        }}<template v-if="e.error">
                            · {{ e.error }}</template
                        ></span
                    >
                    <span v-if="!e.signature_valid" class="text-destructive">{{
                        t('billing.signature_invalid')
                    }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(e.created_at)
                    }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
