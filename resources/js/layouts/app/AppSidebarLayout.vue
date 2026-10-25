<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import GuideBanner from '@/components/GuideBanner.vue';
import GuideTour from '@/components/GuideTour.vue';
import MobileBottomNav from '@/components/MobileBottomNav.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!-- Espace réservé en bas sur téléphone pour la barre de navigation. -->
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-clip pb-20 md:pb-0"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <SubscriptionBanner />
            <GuideBanner />
            <slot />
        </AppContent>
        <Toaster />
        <ConfirmDialog />
        <GuideTour />
        <CommandPalette />
        <MobileBottomNav />
    </AppShell>
</template>
