<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';
import { t } from '@/i18n';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t('settings.appearance.page_title'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="t('settings.appearance.page_title')" />

    <h1 class="sr-only">Appearance settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.appearance.heading')"
            :description="t('settings.appearance.description')"
        />
        <AppearanceTabs />
    </div>
</template>
