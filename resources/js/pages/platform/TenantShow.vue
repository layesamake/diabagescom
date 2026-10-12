<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { auditActionLabel, t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { update as updateSubscription } from '@/routes/platform/subscriptions';
import { grant as grantSupport } from '@/routes/platform/support';
import {
    index as tenantsIndex,
    show as tenantShow,
} from '@/routes/platform/tenants';
import type { SubscriptionSummary } from '@/types';

type Action = 'change_plan' | 'extend_trial' | 'activate' | 'suspend';

const props = defineProps<{
    tenant: {
        id: string;
        name: string;
        legal_name: string;
        country: string;
        status: string;
        created_at: string | null;
    };
    subscription: SubscriptionSummary;
    subscription_id: string | null;
    period: {
        billing_cycle: 'monthly' | 'yearly' | null;
        current_period_end: string | null;
    } | null;
    usage: {
        users: number;
        included_users: number | null;
        warehouses: number;
        warehouse_limit: number | null;
        last_login_at: string | null;
    };
    contacts: { name: string; email: string; role: string }[];
    invoices: {
        id: string;
        number: string;
        plan: string;
        cycle: 'monthly' | 'yearly';
        total: number;
        status: 'open' | 'paid' | 'void';
        due_on: string;
        paid_at: string | null;
    }[];
    payments: {
        id: string;
        provider: string;
        status: string;
        amount: number;
        reference: string;
        proof: string | null;
        created_at: string | null;
    }[];
    history: {
        id: string;
        action: string;
        actor: string | null;
        from: string | null;
        to: string | null;
        plan: string | null;
        reason: string | null;
        created_at: string;
    }[];
    plans: { id: string; code: string; name: string; active: boolean }[];
    canManageBilling: boolean;
    canSupport: boolean;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: t('platform.tenants_title'), href: tenantsIndex() },
        { title: props.tenant.name, href: tenantShow(props.tenant.id) },
    ],
});

const actions: Action[] = [
    'change_plan',
    'extend_trial',
    'activate',
    'suspend',
];
const selected = ref<Action | ''>('');

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';

const statusLabel = (status: string | null) =>
    status ? t(`subscription.status.${status}` as never) : '';

const providerLabel = (provider: string) =>
    t(`platform.record.providers.${provider}` as never);

const paymentStatusLabel = (status: string) =>
    t(`platform.record.payment_status.${status}` as never);

const limit = (used: number, max: number | null) =>
    max === null
        ? t('platform.usage_unlimited', { used })
        : t('platform.usage_of', { used, max });
</script>

<template>
    <Head :title="tenant.name" />

    <div class="space-y-8 p-4 md:p-6">
        <Heading :title="tenant.name" :description="tenant.legal_name" />

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.record.subscription') }}
                </p>
                <p class="mt-1 font-semibold">
                    {{
                        subscription.plan?.name ?? t('platform.no_subscription')
                    }}
                </p>
                <p class="text-sm">{{ statusLabel(subscription.status) }}</p>
                <p
                    v-if="subscription.status === 'trialing'"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('subscription.trial_ends', {
                            date: formatDate(subscription.trial_ends_at),
                        })
                    }}
                </p>
                <p
                    v-else-if="period?.current_period_end"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        period.billing_cycle
                            ? t(`billing.cycles.${period.billing_cycle}`)
                            : ''
                    }}
                    ·
                    {{
                        t('billing.until', {
                            date: formatDate(period.current_period_end),
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.record.users') }}
                </p>
                <p class="mt-1 font-semibold tabular-nums">
                    {{ limit(usage.users, usage.included_users) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.record.warehouses') }}
                </p>
                <p class="mt-1 font-semibold tabular-nums">
                    {{ limit(usage.warehouses, usage.warehouse_limit) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.record.last_login') }}
                </p>
                <p class="mt-1 font-semibold">
                    {{
                        usage.last_login_at
                            ? formatDate(usage.last_login_at)
                            : t('platform.record.never')
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('platform.created', {
                            date: formatDate(tenant.created_at),
                        })
                    }}
                </p>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('platform.record.contacts') }}</h2>
            <p
                v-if="contacts.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('platform.record.no_contact') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="contact in contacts"
                    :key="contact.email"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-40 font-medium">{{ contact.name }}</span>
                    <a
                        :href="`mailto:${contact.email}`"
                        class="flex-1 underline-offset-4 hover:underline"
                        >{{ contact.email }}</a
                    >
                    <span class="text-muted-foreground">{{
                        contact.role
                    }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="canManageBilling && subscription_id"
            class="space-y-3 rounded-xl border p-4"
        >
            <h2 class="font-semibold">{{ t('platform.actions') }}</h2>
            <Form
                v-bind="updateSubscription.form(subscription_id)"
                reset-on-success
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-3 sm:grid-cols-2"
                @success="selected = ''"
            >
                <div class="grid gap-2">
                    <Label for="action">{{ t('platform.actions') }}</Label>
                    <select
                        id="action"
                        v-model="selected"
                        name="action"
                        required
                        :class="selectClass"
                    >
                        <option value="" disabled>
                            {{ t('platform.record.choose_action') }}
                        </option>
                        <option
                            v-for="action in actions"
                            :key="action"
                            :value="action"
                        >
                            {{ t(`platform.action.${action}`) }}
                        </option>
                    </select>
                    <InputError :message="errors.action" />
                </div>

                <div v-if="selected === 'change_plan'" class="grid gap-2">
                    <Label for="plan_id">{{ t('subscription.plan') }}</Label>
                    <select
                        id="plan_id"
                        name="plan_id"
                        required
                        :class="selectClass"
                    >
                        <option
                            v-for="plan in plans"
                            :key="plan.id"
                            :value="plan.id"
                            :selected="
                                plan.code === props.subscription.plan?.code
                            "
                        >
                            {{ plan.name }}
                        </option>
                    </select>
                    <InputError :message="errors.plan_id" />
                </div>
                <div v-else-if="selected === 'extend_trial'" class="grid gap-2">
                    <Label for="days">{{ t('platform.days') }}</Label>
                    <Input
                        id="days"
                        name="days"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="60"
                        required
                        class="h-11"
                    />
                    <InputError :message="errors.days" />
                </div>
                <div v-else-if="selected === 'activate'" class="grid gap-2">
                    <Label for="billing_cycle">{{
                        t('platform.billing_cycle')
                    }}</Label>
                    <select
                        id="billing_cycle"
                        name="billing_cycle"
                        required
                        :class="selectClass"
                    >
                        <option value="monthly">
                            {{ t('platform.monthly') }}
                        </option>
                        <option value="yearly">
                            {{ t('platform.yearly') }}
                        </option>
                    </select>
                    <InputError :message="errors.billing_cycle" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="reason">{{ t('platform.reason') }}</Label>
                    <Input
                        id="reason"
                        name="reason"
                        required
                        minlength="5"
                        maxlength="500"
                        class="h-11"
                    />
                    <InputError :message="errors.reason" />
                </div>

                <Button
                    type="submit"
                    class="h-11 sm:w-fit"
                    :disabled="processing || !selected"
                >
                    {{ t('platform.apply') }}
                </Button>
            </Form>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('platform.record.invoices') }}</h2>
            <p
                v-if="invoices.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-36 font-medium">{{
                        invoice.number
                    }}</span>
                    <span class="flex-1 text-muted-foreground"
                        >{{ invoice.plan }} ·
                        {{ t(`billing.cycles.${invoice.cycle}`) }} ·
                        {{
                            t('billing.due_on', {
                                date: formatDate(invoice.due_on),
                            })
                        }}</span
                    >
                    <span class="tabular-nums">{{
                        formatXof(invoice.total)
                    }}</span>
                    <span class="rounded bg-muted px-2 py-0.5 text-xs">{{
                        t(`billing.status.${invoice.status}`)
                    }}</span>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('platform.record.payments') }}</h2>
            <p
                v-if="payments.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="payment in payments"
                    :key="payment.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <span class="min-w-32 font-medium">{{
                        providerLabel(payment.provider)
                    }}</span>
                    <span class="font-mono text-xs">{{
                        payment.reference
                    }}</span>
                    <span class="flex-1 text-muted-foreground"
                        >{{ paymentStatusLabel(payment.status)
                        }}<template v-if="payment.proof">
                            · {{ payment.proof }}</template
                        ></span
                    >
                    <span class="tabular-nums">{{
                        formatXof(payment.amount)
                    }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(payment.created_at)
                    }}</span>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('platform.record.history') }}</h2>
            <p
                v-if="history.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('billing.none') }}
            </p>
            <ol v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="event in history"
                    :key="event.id"
                    class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3"
                >
                    <span class="min-w-28 text-muted-foreground">{{
                        formatDate(event.created_at)
                    }}</span>
                    <span class="font-medium">{{
                        auditActionLabel(event.action)
                    }}</span>
                    <span
                        v-if="event.from && event.to && event.from !== event.to"
                        class="text-muted-foreground"
                        >{{ statusLabel(event.from) }} →
                        {{ statusLabel(event.to) }}</span
                    >
                    <span class="flex-1 text-muted-foreground">
                        <template v-if="event.reason"
                            >« {{ event.reason }} »</template
                        >
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        event.actor ?? t('audit.system')
                    }}</span>
                </li>
            </ol>
        </section>

        <section v-if="canSupport" class="space-y-3 rounded-xl border p-4">
            <h2 class="font-semibold">{{ t('billing.support') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('platform.record.support_hint') }}
            </p>
            <Form
                v-bind="grantSupport.form(tenant.id)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label for="support-reason">{{
                        t('billing.support_reason')
                    }}</Label>
                    <Input
                        id="support-reason"
                        name="reason"
                        maxlength="500"
                        class="h-11"
                    />
                    <InputError :message="errors.reason" />
                </div>
                <div class="grid gap-1">
                    <Label for="support-minutes">{{
                        t('billing.support_minutes')
                    }}</Label>
                    <Input
                        id="support-minutes"
                        name="minutes"
                        type="number"
                        min="15"
                        max="240"
                        value="30"
                        class="h-11"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    class="h-11"
                    :disabled="processing"
                    >{{ t('billing.support_grant') }}</Button
                >
            </Form>
        </section>
    </div>
</template>
