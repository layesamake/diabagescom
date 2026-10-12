<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import {
    index as tenantsIndex,
    show as tenantShow,
    create as tenantCreate,
} from '@/routes/platform/tenants';
import type { SubscriptionSummary } from '@/types';

type TenantRow = {
    id: string;
    name: string;
    legal_name: string;
    created_at: string | null;
    members: number;
    subscription: SubscriptionSummary;
    subscription_id: string | null;
};

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
    filters: { q: string; status: string; plan: string };
    statuses: string[];
    canManageBilling: boolean;
}>();

const search = ref(props.filters.q);
const status = ref(props.filters.status);
const plan = ref(props.filters.plan);

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';

function submit(): void {
    router.get(
        tenantsIndex().url,
        { q: search.value, status: status.value, plan: plan.value },
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

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
        >
            <div>
                <p class="font-semibold">
                    {{ t('platform.create.quick_title') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ t('platform.create.quick_description') }}
                </p>
            </div>
            <Button as-child class="h-11">
                <Link :href="tenantCreate()">{{
                    t('platform.create.open')
                }}</Link>
            </Button>
        </div>

        <form
            class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_12rem]"
            role="search"
            @submit.prevent="submit"
        >
            <div class="grid gap-1">
                <Label for="q" class="sr-only">{{
                    t('platform.search')
                }}</Label>
                <Input
                    id="q"
                    v-model="search"
                    type="search"
                    class="h-11"
                    :placeholder="t('platform.search')"
                />
            </div>
            <div class="grid gap-1">
                <Label for="status" class="sr-only">{{
                    t('platform.filter_status')
                }}</Label>
                <select
                    id="status"
                    v-model="status"
                    :class="selectClass"
                    @change="submit"
                >
                    <option value="">
                        {{ t('platform.filter_status') }} :
                        {{ t('platform.filter_all') }}
                    </option>
                    <option v-for="code in statuses" :key="code" :value="code">
                        {{ t(`subscription.status.${code}` as never) }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="plan" class="sr-only">{{
                    t('platform.filter_plan')
                }}</Label>
                <select
                    id="plan"
                    v-model="plan"
                    :class="selectClass"
                    @change="submit"
                >
                    <option value="">
                        {{ t('platform.filter_plan') }} :
                        {{ t('platform.filter_all') }}
                    </option>
                    <option
                        v-for="item in plans"
                        :key="item.id"
                        :value="item.code"
                    >
                        {{ item.name }}
                    </option>
                </select>
            </div>
        </form>

        <EmptyState v-if="tenants.data.length === 0">
            {{ t('platform.empty') }}
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="tenant in tenants.data" :key="tenant.id">
                <Link
                    :href="tenantShow(tenant.id)"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 hover:bg-muted/50"
                    :aria-label="`${t('platform.open_record')} — ${tenant.name}`"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold">{{
                            tenant.name
                        }}</span>
                        <span class="block text-sm text-muted-foreground"
                            >{{ tenant.legal_name }} ·
                            {{
                                t('platform.members', { count: tenant.members })
                            }}
                            ·
                            {{
                                t('platform.created', {
                                    date: formatDate(tenant.created_at),
                                })
                            }}</span
                        >
                    </span>
                    <span class="text-sm">
                        <span class="font-medium">{{
                            tenant.subscription.plan?.name ??
                            t('platform.no_subscription')
                        }}</span>
                        —
                        {{
                            t(
                                `subscription.status.${tenant.subscription.status}`,
                            )
                        }}
                        <template
                            v-if="tenant.subscription.status === 'trialing'"
                        >
                            ({{
                                t('subscription.trial_ends', {
                                    date: formatDate(
                                        tenant.subscription.trial_ends_at,
                                    ),
                                })
                            }})
                        </template>
                    </span>
                </Link>
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
