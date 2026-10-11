<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { t } from '@/i18n';

defineOptions({
    layout: {
        title: t('auth.forgot.title'),
        description: t('auth.forgot.description'),
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('auth.forgot.title')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ t('fields.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    :placeholder="t('fields.email_placeholder')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('auth.forgot.submit') }}
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ t('auth.forgot.back') }}</span>
            <TextLink :href="login()">{{
                t('auth.forgot.back_link')
            }}</TextLink>
        </div>
    </div>
</template>
