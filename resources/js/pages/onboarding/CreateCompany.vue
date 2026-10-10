<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { store } from '@/routes/onboarding';

defineOptions({
    layout: {
        title: t('onboarding.title'),
        description: t('onboarding.description'),
    },
});

defineProps<{
    countries: { code: string; name: string }[];
    defaults: {
        country_code: string;
        timezone: string;
        warehouse_code: string;
    };
}>();

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm';
</script>

<template>
    <Head :title="t('onboarding.page_title')" />

    <Form
        v-bind="store.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-8"
    >
        <fieldset class="grid gap-5">
            <legend class="mb-3 text-base font-semibold">
                {{ t('onboarding.company_section') }}
            </legend>

            <div class="grid gap-2">
                <Label for="legal_name">{{ t('onboarding.legal_name') }}</Label>
                <Input
                    id="legal_name"
                    name="legal_name"
                    required
                    v-focus
                    autocomplete="organization"
                    class="h-11"
                    :placeholder="t('onboarding.legal_name_placeholder')"
                />
                <InputError :message="errors.legal_name" />
            </div>

            <div class="grid gap-2">
                <Label for="display_name">
                    {{ t('onboarding.display_name') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    >
                </Label>
                <Input
                    id="display_name"
                    name="display_name"
                    class="h-11"
                    aria-describedby="display_name_help"
                />
                <p id="display_name_help" class="text-sm text-muted-foreground">
                    {{ t('onboarding.display_name_help') }}
                </p>
                <InputError :message="errors.display_name" />
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
                            :selected="country.code === defaults.country_code"
                        >
                            {{ country.name }}
                        </option>
                    </select>
                    <InputError :message="errors.country_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="timezone">{{ t('onboarding.timezone') }}</Label>
                    <Input
                        id="timezone"
                        name="timezone"
                        required
                        class="h-11"
                        :default-value="defaults.timezone"
                    />
                    <InputError :message="errors.timezone" />
                </div>
            </div>
        </fieldset>

        <fieldset class="grid gap-5">
            <legend class="mb-1 text-base font-semibold">
                {{ t('onboarding.warehouse_section') }}
            </legend>
            <p class="-mt-2 text-sm text-muted-foreground">
                {{ t('onboarding.warehouse_help') }}
            </p>

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
                        :placeholder="
                            t('onboarding.warehouse_name_placeholder')
                        "
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
                        autocapitalize="characters"
                        class="h-11 uppercase"
                        :default-value="defaults.warehouse_code"
                        aria-describedby="warehouse_code_help"
                    />
                    <InputError :message="errors.warehouse_code" />
                </div>
            </div>
            <p
                id="warehouse_code_help"
                class="-mt-3 text-sm text-muted-foreground"
            >
                {{ t('onboarding.warehouse_code_help') }}
            </p>

            <div class="grid gap-2">
                <Label for="warehouse_address">
                    {{ t('onboarding.warehouse_address') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    >
                </Label>
                <Input
                    id="warehouse_address"
                    name="warehouse_address"
                    autocomplete="street-address"
                    class="h-11"
                />
                <InputError :message="errors.warehouse_address" />
            </div>
        </fieldset>

        <div class="grid gap-3">
            <Button
                type="submit"
                class="h-11 w-full"
                :disabled="processing"
                data-test="create-company-button"
            >
                <Spinner v-if="processing" />
                {{ t('onboarding.submit') }}
            </Button>
            <p class="text-center text-sm text-muted-foreground">
                {{ t('onboarding.mfa_notice') }}
            </p>
        </div>
    </Form>
</template>
