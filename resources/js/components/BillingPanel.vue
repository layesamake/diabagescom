<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CreditCard, FileText } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import {
    pay as payRoute,
    store as invoiceStore,
    voidMethod as voidRoute,
} from '@/routes/billing/invoices';
import type { PlanOption } from '@/types';

type Invoice = {
    id: string;
    number: string;
    plan: string;
    cycle: 'monthly' | 'yearly';
    lines: {
        label: string;
        quantity: number;
        unit_price: number;
        amount: number;
    }[];
    total: number;
    status: 'open' | 'paid' | 'void';
    due_on: string;
    paid_at: string | null;
    period_end: string | null;
    payments: {
        provider: string;
        status: string;
        reference: string;
        amount: number;
    }[];
};

const props = defineProps<{
    plans: PlanOption[];
    currentPlan: string | null;
    addons: {
        code: string;
        label: string;
        price_monthly: number | null;
        price_yearly: number | null;
    }[];
    invoices: Invoice[];
    providers: string[];
    transferInstructions: string | null;
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const priced = computed(() =>
    props.plans.filter(
        (p) => p.price_monthly != null || p.price_yearly != null,
    ),
);
const form = reactive({
    plan:
        props.currentPlan &&
        priced.value.some((p) => p.code === props.currentPlan)
            ? props.currentPlan
            : (priced.value[0]?.code ?? ''),
    cycle: 'monthly' as 'monthly' | 'yearly',
    addons: Object.fromEntries(props.addons.map((a) => [a.code, 0])) as Record<
        string,
        number
    >,
});
const open = computed(
    () => props.invoices.find((i) => i.status === 'open') ?? null,
);
const selected = computed(() => priced.value.find((p) => p.code === form.plan));
const estimate = computed(() => {
    const plan = selected.value;
    const base =
        (form.cycle === 'yearly' ? plan?.price_yearly : plan?.price_monthly) ??
        0;

    return props.addons.reduce((sum, addon) => {
        const unit =
            (form.cycle === 'yearly'
                ? addon.price_yearly
                : addon.price_monthly) ?? 0;

        return sum + unit * (form.addons[addon.code] ?? 0);
    }, base);
});
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function createInvoice(): void {
    router.post(
        invoiceStore().url,
        { plan: form.plan, cycle: form.cycle, addons: form.addons },
        options,
    );
}

function pay(invoice: Invoice, provider: string): void {
    router.post(
        payRoute(invoice.id).url,
        { provider, idempotency_key: crypto.randomUUID() },
        options,
    );
}

function cancel(invoice: Invoice): void {
    const reason = window.prompt(t('billing.void_reason'))?.trim();

    if (reason) {
        router.post(voidRoute(invoice.id).url, { reason }, options);
    }
}

const statusClass: Record<Invoice['status'], string> = {
    open: 'bg-amber-100 text-amber-950 dark:bg-amber-950 dark:text-amber-50',
    paid: 'bg-emerald-100 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-50',
    void: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <section class="space-y-4">
        <h3 class="font-medium">{{ t('billing.title') }}</h3>

        <!-- Facture en attente : paiement -->
        <article
            v-if="open"
            class="space-y-3 rounded-lg border border-amber-300 p-4 dark:border-amber-700"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold">
                    {{ open.number }} · {{ formatXof(open.total) }}
                </p>
                <span class="text-sm text-muted-foreground">{{
                    t('billing.due_on', { date: formatDate(open.due_on) })
                }}</span>
            </div>
            <ul class="text-sm">
                <li v-for="(line, index) in open.lines" :key="index">
                    {{ line.quantity }} × {{ line.label }} —
                    {{ formatXof(line.amount) }}
                </li>
            </ul>
            <p
                v-for="payment in open.payments.filter(
                    (p) => p.status === 'pending_manual_review',
                )"
                :key="payment.reference"
                class="rounded-md bg-muted p-2 text-sm"
            >
                {{
                    t('billing.transfer_pending', {
                        reference: payment.reference,
                    })
                }}
                <span
                    v-if="transferInstructions"
                    class="block whitespace-pre-line"
                    >{{ transferInstructions }}</span
                >
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="provider in providers"
                    :key="provider"
                    class="h-11"
                    :variant="provider === 'transfer' ? 'outline' : 'default'"
                    :disabled="processing"
                    @click="pay(open, provider)"
                >
                    <CreditCard
                        v-if="provider !== 'transfer'"
                        class="size-4"
                    /><FileText v-else class="size-4" />
                    {{
                        t(
                            `billing.providers.${provider}` as 'billing.providers.transfer',
                        )
                    }}
                </Button>
                <Button
                    variant="ghost"
                    class="h-11"
                    :disabled="processing"
                    @click="cancel(open)"
                    >{{ t('billing.void') }}</Button
                >
            </div>
            <p class="text-xs text-muted-foreground">
                {{ t('billing.verified_only') }}
            </p>
        </article>

        <!-- Nouvelle facture -->
        <article
            v-else-if="priced.length"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="billing-plan">{{ t('billing.plan') }}</Label>
                    <select
                        id="billing-plan"
                        v-model="form.plan"
                        class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option
                            v-for="plan in priced"
                            :key="plan.code"
                            :value="plan.code"
                        >
                            {{ plan.name }}
                        </option>
                    </select>
                </div>
                <fieldset class="grid gap-1">
                    <legend class="mb-1 text-sm font-medium">
                        {{ t('billing.cycle') }}
                    </legend>
                    <div class="flex gap-2">
                        <Button
                            v-for="cycle in ['monthly', 'yearly'] as const"
                            :key="cycle"
                            type="button"
                            class="h-11"
                            :variant="
                                form.cycle === cycle ? 'default' : 'outline'
                            "
                            :disabled="
                                (cycle === 'yearly'
                                    ? selected?.price_yearly
                                    : selected?.price_monthly) == null
                            "
                            @click="form.cycle = cycle"
                            >{{ t(`billing.cycles.${cycle}`) }}</Button
                        >
                    </div>
                </fieldset>
                <div
                    v-for="addon in addons"
                    :key="addon.code"
                    class="grid gap-1"
                >
                    <Label :for="`addon-${addon.code}`">{{
                        addon.label
                    }}</Label>
                    <Input
                        :id="`addon-${addon.code}`"
                        v-model.number="form.addons[addon.code]"
                        type="number"
                        min="0"
                        max="100"
                        class="h-11"
                    />
                </div>
            </div>
            <p class="font-semibold tabular-nums">
                {{ t('billing.estimate', { amount: formatXof(estimate) }) }}
            </p>
            <InputError :message="errors.business ?? errors.plan" />
            <Button
                class="h-11"
                :disabled="processing || !form.plan"
                @click="createInvoice"
                >{{ t('billing.create_invoice') }}</Button
            >
        </article>

        <!-- Historique -->
        <ul v-if="invoices.length" class="divide-y rounded-lg border text-sm">
            <li
                v-for="invoice in invoices"
                :key="invoice.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3"
            >
                <span class="min-w-36 font-medium">{{ invoice.number }}</span>
                <span class="flex-1"
                    >{{ invoice.plan }} ·
                    {{ t(`billing.cycles.${invoice.cycle}`) }}</span
                >
                <span v-if="invoice.period_end" class="text-muted-foreground">{{
                    t('billing.until', { date: formatDate(invoice.period_end) })
                }}</span>
                <span class="tabular-nums">{{ formatXof(invoice.total) }}</span>
                <span
                    class="rounded px-2 py-0.5 text-xs"
                    :class="statusClass[invoice.status]"
                    >{{ t(`billing.status.${invoice.status}`) }}</span
                >
            </li>
        </ul>
    </section>
</template>
