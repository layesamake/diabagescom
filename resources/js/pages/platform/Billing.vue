<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { index as billingIndex } from '@/routes/platform/billing';
import { voidMethod as voidRoute } from '@/routes/platform/billing/invoices';
import { confirm as confirmRoute } from '@/routes/platform/billing/payments';

defineProps<{
    invoices: {
        id: string;
        number: string;
        tenant: string;
        plan: string;
        cycle: 'monthly' | 'yearly';
        total: number;
        status: 'open' | 'paid' | 'void';
        due_on: string;
        paid_at: string | null;
    }[];
    payments: {
        id: string;
        tenant: string;
        provider: string;
        status: string;
        amount: number;
        reference: string;
        reason: string | null;
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
const proofs = reactive<Record<string, string>>({});

function confirmTransfer(id: string): void {
    router.post(
        confirmRoute(id).url,
        { proof_reference: proofs[id] ?? '' },
        { preserveScroll: true },
    );
}

function voidInvoice(id: string): void {
    const reason = window.prompt(t('billing.void_reason'))?.trim();

    if (reason) {
        router.post(voidRoute(id).url, { reason }, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('billing.console_title')" />

    <div class="space-y-8 p-4 md:p-6">
        <Heading :title="t('billing.console_title')" />
        <InputError :message="errors.business ?? errors.proof_reference" />

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.console_payments') }}</h2>
            <p
                v-if="payments.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="p in payments"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-40 font-medium">{{ p.tenant }}</span>
                    <span class="font-mono text-xs">{{ p.reference }}</span>
                    <span class="tabular-nums">{{ formatXof(p.amount) }}</span>
                    <span class="flex-1 text-muted-foreground"
                        >{{ p.status
                        }}<template v-if="p.reason">
                            · {{ p.reason }}</template
                        ></span
                    >
                    <template v-if="p.status === 'pending_manual_review'">
                        <Input
                            v-model="proofs[p.id]"
                            class="h-10 w-48"
                            :placeholder="t('billing.proof')"
                            :aria-label="t('billing.proof')"
                        />
                        <Button class="h-10" @click="confirmTransfer(p.id)">{{
                            t('billing.confirm_transfer')
                        }}</Button>
                    </template>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('billing.console_invoices') }}</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="i in invoices"
                    :key="i.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-36 font-medium">{{ i.number }}</span>
                    <span class="min-w-40">{{ i.tenant }}</span>
                    <span class="flex-1 text-muted-foreground"
                        >{{ i.plan }} · {{ t(`billing.cycles.${i.cycle}`) }} ·
                        {{ formatDate(i.due_on) }}</span
                    >
                    <span class="tabular-nums">{{ formatXof(i.total) }}</span>
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
