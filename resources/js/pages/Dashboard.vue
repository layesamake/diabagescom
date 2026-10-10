<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { TriangleAlert, Truck } from '@lucide/vue';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { dashboard } from '@/routes';
import { index as stockIndex } from '@/routes/stock';
import { index as transfersIndex } from '@/routes/transfers';

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

defineProps<{
    alerts: { low_stock?: number; transfers_to_receive?: number };
}>();

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

        <section
            v-if="alerts.low_stock !== undefined"
            class="space-y-2"
            aria-labelledby="alerts-title"
        >
            <h2 id="alerts-title" class="font-semibold">
                {{ t('dashboard.alerts_title') }}
            </h2>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li v-if="alerts.low_stock">
                    <Link
                        :href="stockIndex({ query: { low: 1 } })"
                        class="flex min-h-16 items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
                    >
                        <TriangleAlert
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        {{
                            t('dashboard.low_stock', {
                                count: alerts.low_stock,
                            })
                        }}
                    </Link>
                </li>
                <li v-if="alerts.transfers_to_receive">
                    <Link
                        :href="transfersIndex()"
                        class="flex min-h-16 items-center gap-3 rounded-lg border p-4 hover:bg-accent"
                    >
                        <Truck class="size-5 shrink-0" aria-hidden="true" />
                        {{
                            t('dashboard.transfers_to_receive', {
                                count: alerts.transfers_to_receive,
                            })
                        }}
                    </Link>
                </li>
                <li
                    v-if="!alerts.low_stock && !alerts.transfers_to_receive"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('dashboard.no_alerts') }}
                </li>
            </ul>
        </section>

        <div
            class="rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
        >
            {{ t('dashboard.coming_soon') }}
        </div>
    </div>
</template>
