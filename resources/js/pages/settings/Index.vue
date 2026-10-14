<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Bell,
    Building2,
    CreditCard,
    DatabaseBackup,
    Palette,
    ScrollText,
    ShieldCheck,
    Smartphone,
    UserRound,
    Users,
    Warehouse,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { edit as appearanceEdit } from '@/routes/appearance';
import { index as auditIndex } from '@/routes/audit';
import { index as backupIndex } from '@/routes/backup';
import { edit as companyEdit } from '@/routes/company';
import { index as membersIndex } from '@/routes/members';
import { edit as notificationsEdit } from '@/routes/notifications';
import { edit as profileEdit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';
import { index as settings } from '@/routes/settings';
import { show as subscriptionShow } from '@/routes/subscription';
import { index as devicesIndex } from '@/routes/sync/devices';
import { index as warehousesIndex } from '@/routes/warehouses';

/**
 * Vue d'ensemble des paramètres : chaque réglage accessible à l'utilisateur,
 * regroupé et expliqué en une phrase, pour ne pas avoir à deviner où il se cache.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('settings_hub.page_title'), href: settings() },
        ],
    },
});

const { context, can } = useAppContext();

type Item = { key: string; title: string; href: string; icon: Component };

const sections = computed(() => {
    const item = (
        show: boolean,
        key: string,
        title: string,
        href: string,
        icon: Component,
    ): Item[] => (show ? [{ key, title, href, icon }] : []);
    const inCompany = Boolean(context.value);

    return [
        {
            key: 'account',
            items: [
                ...item(
                    true,
                    'profile',
                    t('nav.profile'),
                    profileEdit().url,
                    UserRound,
                ),
                ...item(
                    true,
                    'security',
                    t('nav.security'),
                    securityEdit().url,
                    ShieldCheck,
                ),
                ...item(
                    true,
                    'appearance',
                    t('nav.appearance'),
                    appearanceEdit().url,
                    Palette,
                ),
                ...item(
                    inCompany,
                    'notifications',
                    t('notifications.nav'),
                    notificationsEdit().url,
                    Bell,
                ),
            ],
        },
        {
            key: 'company',
            items: [
                ...item(
                    can('organization.view'),
                    'company',
                    t('company.nav'),
                    companyEdit().url,
                    Building2,
                ),
                ...item(
                    can('member.view'),
                    'members',
                    t('team.nav'),
                    membersIndex().url,
                    Users,
                ),
                ...item(
                    can('warehouse.view'),
                    'warehouses',
                    t('nav.warehouses'),
                    warehousesIndex().url,
                    Warehouse,
                ),
                ...item(
                    inCompany,
                    'subscription',
                    t('nav.subscription'),
                    subscriptionShow().url,
                    CreditCard,
                ),
            ],
        },
        {
            key: 'data',
            items: [
                ...item(
                    can('organization.manage'),
                    'backup',
                    t('backup.nav'),
                    backupIndex().url,
                    DatabaseBackup,
                ),
                ...item(
                    can('audit.view'),
                    'audit',
                    t('nav.audit'),
                    auditIndex().url,
                    ScrollText,
                ),
                ...item(
                    inCompany,
                    'devices',
                    t('nav.devices'),
                    devicesIndex().url,
                    Smartphone,
                ),
            ],
        },
    ].filter((section) => section.items.length > 0);
});
</script>

<template>
    <Head :title="t('settings_hub.page_title')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-8 p-4 md:p-8">
        <Heading
            :title="t('settings_hub.heading')"
            :description="t('settings_hub.description')"
        />

        <section
            v-for="section in sections"
            :key="section.key"
            class="space-y-3"
            :aria-labelledby="`settings-${section.key}`"
        >
            <h2
                :id="`settings-${section.key}`"
                class="text-lg font-semibold tracking-tight"
            >
                {{
                    t(`settings_hub.sections.${section.key}` as TranslationKey)
                }}
            </h2>

            <ul class="grid gap-3 sm:grid-cols-2">
                <li v-for="item in section.items" :key="item.key">
                    <Link
                        :href="item.href"
                        class="flex min-h-20 items-start gap-3 rounded-lg border bg-card p-4 transition-colors hover:bg-accent"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <component
                                :is="item.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-medium">{{
                                item.title
                            }}</span>
                            <span class="block text-sm text-muted-foreground">{{
                                t(
                                    `settings_hub.items.${item.key}` as TranslationKey,
                                )
                            }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
