<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import {
    index as tenantsIndex,
    store as createTenant,
} from '@/routes/platform/tenants';
import type { PlanOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('platform.tenants_title'), href: tenantsIndex() },
            { title: t('platform.create.title'), href: '#' },
        ],
    },
});

defineProps<{
    plans: PlanOption[];
    trialDays: number;
    countries: { code: string; name: string }[];
    defaults: {
        country_code: string;
        timezone: string;
        warehouse_code: string;
        plan_code: string;
    };
}>();

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm';
</script>

<template>
    <Head :title="t('platform.create.page_title')" />

    <div class="space-y-8 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ t('platform.create.title') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('platform.create.description') }}
                </p>
            </div>
            <Link
                :href="tenantsIndex()"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
            >
                {{ t('common.back') }}
            </Link>
        </div>

        <Form
            v-bind="createTenant.form()"
            v-slot="{ errors, processing }"
            class="max-w-4xl space-y-8"
        >
            <fieldset class="grid gap-5 rounded-xl border p-4 md:p-6">
                <legend class="px-1 text-base font-semibold">
                    {{ t('platform.create.owner_section') }}
                </legend>
                <p class="-mt-2 text-sm text-muted-foreground">
                    {{ t('platform.create.owner_help') }}
                </p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="owner_name">{{
                            t('platform.create.owner_name')
                        }}</Label>
                        <Input
                            id="owner_name"
                            name="owner_name"
                            required
                            v-focus
                            autocomplete="name"
                            class="h-11"
                        />
                        <InputError :message="errors.owner_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="owner_email">{{
                            t('platform.create.owner_email')
                        }}</Label>
                        <Input
                            id="owner_email"
                            name="owner_email"
                            type="email"
                            required
                            autocomplete="email"
                            class="h-11"
                        />
                        <InputError :message="errors.owner_email" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="grid gap-5 rounded-xl border p-4 md:p-6">
                <legend class="px-1 text-base font-semibold">
                    {{ t('platform.create.company_section') }}
                </legend>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="legal_name">{{
                            t('onboarding.legal_name')
                        }}</Label>
                        <Input
                            id="legal_name"
                            name="legal_name"
                            required
                            class="h-11"
                        />
                        <InputError :message="errors.legal_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="display_name">{{
                            t('onboarding.display_name')
                        }}</Label>
                        <Input
                            id="display_name"
                            name="display_name"
                            class="h-11"
                        />
                        <InputError :message="errors.display_name" />
                    </div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="country_code">{{
                            t('onboarding.country')
                        }}</Label>
                        <select
                            id="country_code"
                            name="country_code"
                            :class="selectClass"
                        >
                            <option
                                v-for="country in countries"
                                :key="country.code"
                                :value="country.code"
                                :selected="
                                    country.code === defaults.country_code
                                "
                            >
                                {{ country.name }}
                            </option>
                        </select>
                        <InputError :message="errors.country_code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="timezone">{{
                            t('onboarding.timezone')
                        }}</Label>
                        <Input
                            id="timezone"
                            name="timezone"
                            required
                            :default-value="defaults.timezone"
                            class="h-11"
                        />
                        <InputError :message="errors.timezone" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="grid gap-5 rounded-xl border p-4 md:p-6">
                <legend class="px-1 text-base font-semibold">
                    {{ t('platform.create.warehouse_section') }}
                </legend>
                <div class="grid gap-5 sm:grid-cols-[2fr_1fr]">
                    <div class="grid gap-2">
                        <Label for="warehouse_name">{{
                            t('onboarding.warehouse_name')
                        }}</Label>
                        <Input
                            id="warehouse_name"
                            name="warehouse_name"
                            required
                            class="h-11"
                        />
                        <InputError :message="errors.warehouse_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="warehouse_code">{{
                            t('onboarding.warehouse_code')
                        }}</Label>
                        <Input
                            id="warehouse_code"
                            name="warehouse_code"
                            required
                            maxlength="16"
                            class="h-11 uppercase"
                            :default-value="defaults.warehouse_code"
                        />
                        <InputError :message="errors.warehouse_code" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="warehouse_address">{{
                        t('onboarding.warehouse_address')
                    }}</Label>
                    <Input
                        id="warehouse_address"
                        name="warehouse_address"
                        class="h-11"
                    />
                    <InputError :message="errors.warehouse_address" />
                </div>
            </fieldset>

            <fieldset class="grid gap-3 rounded-xl border p-4 md:p-6">
                <legend class="px-1 text-base font-semibold">
                    {{ t('platform.create.plan_section') }}
                </legend>
                <p class="-mt-2 text-sm text-muted-foreground">
                    {{ t('platform.create.plan_help', { days: trialDays }) }}
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="plan in plans"
                        :key="plan.code"
                        class="flex min-h-11 cursor-pointer gap-3 rounded-lg border p-3 has-[:checked]:border-[var(--color-brand-teal)] has-[:checked]:ring-1 has-[:checked]:ring-[var(--color-brand-teal)]"
                    >
                        <input
                            type="radio"
                            name="plan_code"
                            :value="plan.code"
                            :checked="plan.code === defaults.plan_code"
                            required
                            class="mt-1 size-4 accent-[var(--color-brand-teal)]"
                        />
                        <span class="grid gap-0.5 text-sm">
                            <span class="font-semibold">{{ plan.name }}</span>
                            <span class="text-muted-foreground">
                                {{
                                    plan.warehouse_limit === null
                                        ? t('subscription.unlimited')
                                        : t('subscription.warehouses_count', {
                                              count: plan.warehouse_limit,
                                          })
                                }}
                                ·
                                {{
                                    t('subscription.users_count', {
                                        count: plan.included_users,
                                    })
                                }}
                            </span>
                        </span>
                    </label>
                </div>
                <InputError :message="errors.plan_code" />
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    class="h-11"
                    :disabled="processing"
                    data-test="create-platform-tenant-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('platform.create.submit') }}
                </Button>
                <span class="text-sm text-muted-foreground">
                    {{ t('platform.create.invitation_notice') }}
                </span>
            </div>
        </Form>
    </div>
</template>
