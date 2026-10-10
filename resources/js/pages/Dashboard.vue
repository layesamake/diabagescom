<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t('nav.dashboard'),
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
const { context } = useAppContext();
</script>

<template>
    <Head :title="t('dashboard.page_title')" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <section class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{
                    t('dashboard.welcome', { name: page.props.auth.user.name })
                }}
            </h1>
            <p v-if="context" class="text-muted-foreground">
                {{ t('dashboard.subtitle', { company: context.tenant.name }) }}
                {{ t('dashboard.role', { role: context.role.name }) }}
            </p>
        </section>

        <div
            class="rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
        >
            {{ t('dashboard.coming_soon') }}
        </div>
    </div>
</template>
