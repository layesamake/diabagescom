<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { edit, update } from '@/routes/notifications';

type Choice = { in_app: boolean; email: boolean };

const props = defineProps<{
    categories: string[];
    preferences: Record<string, Choice>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('notifications.page_title'), href: edit() }],
    },
});

const choices = reactive<Record<string, Choice>>(
    Object.fromEntries(
        props.categories.map((c) => [c, { ...props.preferences[c] }]),
    ),
);
const processing = ref(false);

function save(): void {
    router.put(
        update().url,
        { preferences: choices },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="t('notifications.page_title')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('notifications.heading')"
            :description="t('notifications.description')"
        />

        <p v-if="categories.length === 0" class="text-sm text-muted-foreground">
            {{ t('notifications.empty') }}
        </p>

        <form v-else class="space-y-4" @submit.prevent="save">
            <fieldset class="divide-y rounded-lg border">
                <legend class="sr-only">
                    {{ t('notifications.heading') }}
                </legend>
                <div
                    class="hidden items-center gap-4 px-4 py-2 text-xs font-medium text-muted-foreground sm:grid sm:grid-cols-[1fr_7rem_9rem]"
                >
                    <span>{{ t('notifications.topic') }}</span>
                    <span>{{ t('notifications.in_app') }}</span>
                    <span>{{ t('notifications.email') }}</span>
                </div>
                <div
                    v-for="category in categories"
                    :key="category"
                    class="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_7rem_9rem] sm:items-center sm:gap-4"
                >
                    <span class="text-sm font-medium">{{
                        t(
                            `notifications.categories.${category}` as TranslationKey,
                        )
                    }}</span>
                    <label class="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            v-model="choices[category].in_app"
                            type="checkbox"
                            class="size-5"
                        />
                        <span class="sm:sr-only">{{
                            t('notifications.in_app')
                        }}</span>
                    </label>
                    <label class="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            v-model="choices[category].email"
                            type="checkbox"
                            class="size-5"
                        />
                        <span class="sm:sr-only">{{
                            t('notifications.email')
                        }}</span>
                    </label>
                </div>
            </fieldset>

            <p class="text-xs text-muted-foreground">
                {{ t('notifications.email_hint') }}
            </p>

            <Button type="submit" class="h-11" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('notifications.save') }}
            </Button>
        </form>
    </div>
</template>
