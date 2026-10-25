<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { edit, update } from '@/routes/features';

type FeatureState = { included: boolean; activated: boolean };

const props = defineProps<{
    features: Record<'accounting' | 'online_store', FeatureState>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('nav.features'), href: edit() }],
    },
});

const processing = ref<string | null>(null);

function toggle(
    feature: 'accounting' | 'online_store',
    enabled: boolean,
): void {
    processing.value = feature;
    router.put(
        update(feature).url,
        { enabled },
        {
            preserveScroll: true,
            onFinish: () => (processing.value = null),
        },
    );
}
</script>

<template>
    <Head :title="t('nav.features')" />

    <div class="mx-auto max-w-2xl space-y-6 p-4 md:p-6">
        <Heading
            :title="t('nav.features')"
            :description="t('features.description')"
        />

        <ul class="divide-y rounded-lg border">
            <li
                v-for="feature in ['accounting', 'online_store'] as const"
                :key="feature"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ t(`features.labels.${feature}` as TranslationKey) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            features[feature].included
                                ? t('features.hint')
                                : t('features.not_included')
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    :variant="
                        features[feature].activated ? 'outline' : 'default'
                    "
                    class="h-11 shrink-0"
                    :disabled="
                        !features[feature].included || processing === feature
                    "
                    @click="toggle(feature, !features[feature].activated)"
                >
                    <Spinner v-if="processing === feature" />
                    {{
                        features[feature].activated
                            ? t('features.deactivate')
                            : t('features.activate')
                    }}
                </Button>
            </li>
        </ul>
    </div>
</template>
