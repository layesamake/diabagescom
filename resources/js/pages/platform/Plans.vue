<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { index as plansIndex, update } from '@/routes/platform/plans';

type PlanRow = {
    id: string;
    code: string;
    name: string;
    warehouse_limit: number | null;
    included_users: number;
    accounting_enabled: boolean;
    online_store_enabled: boolean;
    price_monthly: number | null;
    price_yearly: number | null;
    active: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('platform.plans_title'), href: plansIndex() }],
    },
});

defineProps<{ plans: PlanRow[] }>();

// Les cases à cocher non cochées ne sont pas envoyées : on transmet toujours 0/1.
const withBooleans = (
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> => ({
    ...data,
    accounting_enabled: data.accounting_enabled ? 1 : 0,
    online_store_enabled: data.online_store_enabled ? 1 : 0,
    active: data.active ? 1 : 0,
    warehouse_limit: data.warehouse_limit === '' ? null : data.warehouse_limit,
    price_monthly: data.price_monthly === '' ? null : data.price_monthly,
    price_yearly: data.price_yearly === '' ? null : data.price_yearly,
});
</script>

<template>
    <Head :title="t('platform.plans_title')" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('platform.plans_title')"
            :description="t('platform.plans_description')"
        />

        <Form
            v-for="plan in plans"
            :key="plan.id"
            v-bind="update.form(plan.id)"
            :transform="withBooleans"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <p
                class="font-mono text-xs text-muted-foreground sm:col-span-2 lg:col-span-3"
            >
                {{ plan.code }}
            </p>
            <div class="grid gap-2">
                <Label :for="`name-${plan.id}`">{{
                    t('platform.plan_name')
                }}</Label>
                <Input
                    :id="`name-${plan.id}`"
                    name="name"
                    :default-value="plan.name"
                    required
                    class="h-11"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label :for="`wh-${plan.id}`">{{
                    t('platform.warehouse_limit')
                }}</Label>
                <Input
                    :id="`wh-${plan.id}`"
                    name="warehouse_limit"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    :default-value="plan.warehouse_limit ?? ''"
                    class="h-11"
                />
                <InputError :message="errors.warehouse_limit" />
            </div>
            <div class="grid gap-2">
                <Label :for="`users-${plan.id}`">{{
                    t('platform.included_users')
                }}</Label>
                <Input
                    :id="`users-${plan.id}`"
                    name="included_users"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    required
                    :default-value="plan.included_users"
                    class="h-11"
                />
                <InputError :message="errors.included_users" />
            </div>
            <div class="grid gap-2">
                <Label :for="`pm-${plan.id}`">{{
                    t('platform.price_monthly')
                }}</Label>
                <Input
                    :id="`pm-${plan.id}`"
                    name="price_monthly"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    step="1"
                    :default-value="plan.price_monthly ?? ''"
                    class="h-11"
                />
                <InputError :message="errors.price_monthly" />
            </div>
            <div class="grid gap-2">
                <Label :for="`py-${plan.id}`">{{
                    t('platform.price_yearly')
                }}</Label>
                <Input
                    :id="`py-${plan.id}`"
                    name="price_yearly"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    step="1"
                    :default-value="plan.price_yearly ?? ''"
                    class="h-11"
                />
                <InputError :message="errors.price_yearly" />
            </div>
            <div class="flex flex-col justify-center gap-3">
                <label class="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        name="accounting_enabled"
                        value="1"
                        :checked="plan.accounting_enabled"
                        class="size-5"
                    />
                    {{ t('platform.accounting') }}
                </label>
                <label class="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        name="online_store_enabled"
                        value="1"
                        :checked="plan.online_store_enabled"
                        class="size-5"
                    />
                    {{ t('platform.online_store') }}
                </label>
                <label class="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        :checked="plan.active"
                        class="size-5"
                    />
                    {{ t('platform.active') }}
                </label>
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label :for="`reason-${plan.id}`">{{
                    t('platform.reason')
                }}</Label>
                <Input
                    :id="`reason-${plan.id}`"
                    name="reason"
                    required
                    minlength="5"
                    class="h-11"
                />
                <InputError :message="errors.reason" />
            </div>
            <div class="flex items-end">
                <Button
                    type="submit"
                    class="h-11 w-full"
                    :disabled="processing"
                >
                    {{ t('platform.save_plan') }}
                </Button>
            </div>
        </Form>
    </div>
</template>
