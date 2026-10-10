<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { t } from '@/i18n';

defineProps<{ prev: string | null; next: string | null }>();
</script>

<template>
    <nav v-if="prev || next" class="flex justify-between">
        <Link
            v-if="prev"
            :href="prev"
            class="inline-flex min-h-11 items-center underline underline-offset-4"
            >{{ t('common.previous') }}</Link
        >
        <span v-else />
        <Link
            v-if="next"
            :href="next"
            class="inline-flex min-h-11 items-center underline underline-offset-4"
            >{{ t('common.next') }}</Link
        >
    </nav>
</template>
