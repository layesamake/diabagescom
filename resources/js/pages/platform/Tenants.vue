<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { index as tenantsIndex } from '@/routes/platform/tenants';
import { update as updateSubscription } from '@/routes/platform/subscriptions';
import { grant as grantSupport } from '@/routes/platform/support';
import type { SubscriptionSummary } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type TenantRow = {
    id: string;
    name: string;
    legal_name: string;
    created_at: string | null;
    members: number;
    subscription: SubscriptionSummary;
    subscription_id: string | null;
};

type Action = 'change_plan' | 'extend_trial' | 'activate' | 'suspend';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('platform.tenants_title'), href: tenantsIndex() },
        ],
    },
});

const props = defineProps<{
    tenants: {
        data: TenantRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    plans: { id: string; code: string; name: string; active: boolean }[];
    filters: { q: string };
    canManageBilling: boolean;
}>();

const search = ref(props.filters.q);
const actions: Action[] = [
    'change_plan',
    'extend_trial',
    'activate',
    'suspend',
];
const selected = ref<Record<string, Action>>({});

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';

function submitSearch(): void {
    router.get(
        tenantsIndex().url,
        { q: search.value },
        { preserveState: true },
    );
}
</script>

<template>
    <Head :title="t('platform.tenants_title')" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('platform.tenants_title')"
            :description="t('platform.tenants_description')"
        />

        <form class="flex gap-2" role="search" @submit.prevent="submitSearch">
            <Label for="q" class="sr-only">{{ t('platform.search') }}</Label>
            <Input
                id="q"
                v-model="search"
                type="search"
                class="h-11 max-w-md"
                :placeholder="t('platform.search')"
            />
        </form>

        <EmptyState v-if="tenants.data.length === 0">
            {{ t('platform.empty') }}
        </EmptyState>

        <ul class="space-y-4">
            <li
                v-for="tenant in tenants.data"
                :key="tenant.id"
                class="space-y-3 rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="text-lg font-semibold">{{ tenant.name }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        tenant.legal_name
                    }}</span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.members', { count: tenant.members }) }} ·
                    {{
                        t('platform.created', {
                            date: formatDate(tenant.created_at),
                        })
                    }}
                </p>
                <p class="text-sm">
                    <span class="font-medium">{{
                        tenant.subscription.plan?.name ??
                        t('platform.no_subscription')
                    }}</span>
                    —
                    {{ t(`subscription.status.${tenant.subscription.status}`) }}
                    <template v-if="tenant.subscription.status === 'trialing'">
                        ({{
                            t('subscription.trial_ends', {
                                date: formatDate(
                                    tenant.subscription.trial_ends_at,
                                ),
                            })
                        }})
                    </template>
                </p>

                <Form
                    v-if="canManageBilling && tenant.subscription_id"
                    v-bind="updateSubscription.form(tenant.subscription_id)"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="grid gap-3 border-t pt-3 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label :for="`action-${tenant.id}`">{{
                            t('platform.actions')
                        }}</Label>
                        <select
                            :id="`action-${tenant.id}`"
                            v-model="selected[tenant.id]"
                            name="action"
                            required
                            :class="selectClass"
                        >
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

                    <div
                        v-if="selected[tenant.id] === 'change_plan'"
                        class="grid gap-2"
                    >
                        <Label :for="`plan-${tenant.id}`">{{
                            t('subscription.plan')
                        }}</Label>
                        <select
                            :id="`plan-${tenant.id}`"
                            name="plan_id"
                            required
                            :class="selectClass"
                        >
                            <option
                                v-for="plan in plans"
                                :key="plan.id"
                                :value="plan.id"
                            >
                                {{ plan.name }}
                            </option>
                        </select>
                        <InputError :message="errors.plan_id" />
                    </div>
                    <div
                        v-else-if="selected[tenant.id] === 'extend_trial'"
                        class="grid gap-2"
                    >
                        <Label :for="`days-${tenant.id}`">{{
                            t('platform.days')
                        }}</Label>
                        <Input
                            :id="`days-${tenant.id}`"
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
                    <div
                        v-else-if="selected[tenant.id] === 'activate'"
                        class="grid gap-2"
                    >
                        <Label :for="`cycle-${tenant.id}`">{{
                            t('platform.billing_cycle')
                        }}</Label>
                        <select
                            :id="`cycle-${tenant.id}`"
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
                        <Label :for="`reason-${tenant.id}`">{{
                            t('platform.reason')
                        }}</Label>
                        <Input
                            :id="`reason-${tenant.id}`"
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
                        :disabled="processing || !selected[tenant.id]"
                    >
                        {{ t('platform.apply') }}
                    </Button>
                </Form>

                <Form
                    v-bind="grantSupport.form(tenant.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="grid gap-3 border-t pt-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
                >
                    <div class="grid gap-1">
                        <Label :for="`support-reason-${tenant.id}`">{{
                            t('billing.support_reason')
                        }}</Label>
                        <Input
                            :id="`support-reason-${tenant.id}`"
                            name="reason"
                            maxlength="500"
                            class="h-11"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`support-minutes-${tenant.id}`">{{
                            t('billing.support_minutes')
                        }}</Label>
                        <Input
                            :id="`support-minutes-${tenant.id}`"
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
            </li>
        </ul>

        <nav
            v-if="tenants.prev_page_url || tenants.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="tenants.prev_page_url"
                :href="tenants.prev_page_url"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="tenants.next_page_url"
                :href="tenants.next_page_url"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
