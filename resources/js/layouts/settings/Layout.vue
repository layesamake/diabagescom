<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';
import { t } from '@/i18n';
import { useAppContext } from '@/composables/useAppContext';
import { index as auditIndex } from '@/routes/audit';
import { index as backupIndex } from '@/routes/backup';
import { index as membersIndex } from '@/routes/members';
import { show as subscriptionShow } from '@/routes/subscription';
import { index as devicesIndex } from '@/routes/sync/devices';
import { index as warehousesIndex } from '@/routes/warehouses';

const { context, can } = useAppContext();

const sidebarNavItems: NavItem[] = [
    {
        title: t('nav.profile'),
        href: editProfile(),
    },
    {
        title: t('nav.security'),
        href: editSecurity(),
    },
    {
        title: t('nav.appearance'),
        href: editAppearance(),
    },
    // Rubriques d'entreprise : visibles seulement dans un contexte d'entreprise.
    ...(context.value
        ? [{ title: t('nav.subscription'), href: subscriptionShow() }]
        : []),
    ...(can('member.view')
        ? [{ title: t('team.nav'), href: membersIndex() }]
        : []),
    ...(can('warehouse.view')
        ? [{ title: t('nav.warehouses'), href: warehousesIndex() }]
        : []),
    ...(can('audit.view')
        ? [{ title: t('nav.audit'), href: auditIndex() }]
        : []),
    ...(can('organization.manage')
        ? [{ title: t('backup.nav'), href: backupIndex() }]
        : []),
    ...(context.value
        ? [{ title: t('nav.devices'), href: devicesIndex() }]
        : []),
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            :title="t('settings.title')"
            :description="t('settings.description')"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="t('nav.settings_menu')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
