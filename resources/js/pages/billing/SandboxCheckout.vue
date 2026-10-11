<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { decide as decideRoute } from '@/routes/billing/sandbox';

const props = defineProps<{
    payment: {
        reference: string;
        amount: number;
        status: string;
        invoice: string;
    };
}>();

const processing = ref(false);

function decide(outcome: 'succeeded' | 'failed'): void {
    router.post(
        decideRoute(props.payment.reference).url,
        { outcome },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="t('billing.sandbox_title')" />

    <main
        class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 p-4"
    >
        <div class="flex items-center gap-3">
            <AppLogoIcon class="size-10" />
            <h1 class="text-xl font-semibold">
                {{ t('billing.sandbox_title') }}
            </h1>
        </div>
        <p
            class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
        >
            {{ t('billing.sandbox_hint') }}
        </p>
        <dl class="space-y-1 rounded-lg border p-4 text-sm">
            <div class="flex justify-between">
                <dt>{{ t('billing.invoice') }}</dt>
                <dd class="font-medium">{{ payment.invoice }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>{{ t('billing.reference') }}</dt>
                <dd class="font-mono text-xs">{{ payment.reference }}</dd>
            </div>
            <div class="flex justify-between text-lg font-bold">
                <dt>{{ t('billing.amount') }}</dt>
                <dd class="tabular-nums">{{ formatXof(payment.amount) }}</dd>
            </div>
        </dl>
        <div class="grid gap-2">
            <Button
                class="h-12"
                :disabled="processing"
                @click="decide('succeeded')"
                >{{ t('billing.sandbox_approve') }}</Button
            >
            <Button
                variant="outline"
                class="h-12"
                :disabled="processing"
                @click="decide('failed')"
                >{{ t('billing.sandbox_decline') }}</Button
            >
        </div>
    </main>
</template>
