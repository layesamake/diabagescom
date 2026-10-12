<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ContextBar from '@/components/ContextBar.vue';
import PageHelp from '@/components/PageHelp.vue';
import SyncBadge from '@/components/SyncBadge.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex shrink-0 flex-col border-b border-sidebar-border/70 px-4 py-2 md:px-4"
    >
        <div class="flex min-h-11 items-center gap-2">
            <SidebarTrigger class="-ml-1 size-11 md:size-8" />
            <ContextBar />
            <PageHelp />
            <SyncBadge />
        </div>
        <div v-if="breadcrumbs && breadcrumbs.length > 0" class="pl-1">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </header>
</template>
