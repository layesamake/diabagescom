<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

export type NavGroup = { label?: string; tour?: string; items: NavItem[] };

defineProps<{
    groups: NavGroup[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <!-- Blocs séparés par un filet fin ; titres en petites capitales, plus contrastés. -->
    <SidebarGroup
        v-for="(group, index) in groups.filter((g) => g.items.length > 0)"
        :key="group.label ?? 'main'"
        class="px-2 py-2"
        :class="{ 'border-t border-sidebar-foreground/15': index > 0 }"
        :data-tour="group.tour"
    >
        <SidebarGroupLabel
            v-if="group.label"
            class="mb-1 h-7 text-[11px] font-semibold tracking-wider text-sidebar-foreground/90 uppercase dark:text-[var(--color-brand-teal-light)]"
            >{{ group.label }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    size="lg"
                    class="h-10"
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.tooltip ?? item.title"
                    :data-tour="item.tour"
                >
                    <a
                        v-if="item.external"
                        :href="String(item.href)"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </a>
                    <Link v-else :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="item.badge">{{
                    item.badge
                }}</SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
