<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Pager from '@/components/Pager.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import {
    create as requestCreate,
    index as requestsIndex,
    show as requestShow,
} from '@/routes/purchases/requests';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type Status =
    | 'draft'
    | 'submitted'
    | 'approved'
    | 'rejected'
    | 'ordered'
    | 'cancelled';
type Row = {
    id: string;
    number: string | null;
    status: Status;
    warehouse: { name: string };
    author: string;
    lines_count: number;
    created_at: string | null;
};

const props = defineProps<{
    requests: Paginated<Row>;
    filters: { status: string };
    can: { create: boolean; approve: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('purchases.requests.title'), href: requestsIndex() },
        ],
    },
});

const tabs: { value: string; label: string }[] = [
    { value: '', label: t('purchases.all') },
    ...(props.can.approve
        ? [{ value: 'pending', label: t('purchases.requests.filter_pending') }]
        : []),
    { value: 'draft', label: t('purchases.requests.status.draft') },
    { value: 'approved', label: t('purchases.requests.status.approved') },
    { value: 'ordered', label: t('purchases.requests.status.ordered') },
];

function filter(status: string): void {
    router.get(
        requestsIndex({ query: status ? { status } : {} }).url,
        {},
        { preserveState: true },
    );
}

const badge: Record<Status, string> = {
    draft: 'bg-muted text-foreground',
    submitted: 'bg-info-soft text-info-soft-foreground',
    approved: 'bg-success-soft text-success-soft-foreground',
    rejected: 'bg-danger-soft text-danger-soft-foreground',
    ordered: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <Head :title="t('purchases.requests.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('purchases.requests.title')"
                :description="t('purchases.requests.description')"
            />
            <Button v-if="can.create" as-child class="h-11"
                ><Link :href="requestCreate()"
                    ><Plus class="size-4" />{{
                        t('purchases.requests.new')
                    }}</Link
                ></Button
            >
        </div>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="tab in tabs"
                :key="tab.value"
                role="tab"
                :aria-selected="filters.status === tab.value"
                :variant="filters.status === tab.value ? 'default' : 'outline'"
                class="h-11"
                @click="filter(tab.value)"
                >{{ tab.label }}</Button
            >
        </div>

        <EmptyState v-if="requests.data.length === 0">
            {{ t('purchases.requests.empty') }}
            <template v-if="can.create" #action>
                <Button as-child class="h-11"
                    ><Link :href="requestCreate()"
                        ><Plus class="size-4" />{{
                            t('purchases.requests.new')
                        }}</Link
                    ></Button
                >
            </template>
        </EmptyState>
        <ul v-else class="divide-y rounded-lg border">
            <li v-for="r in requests.data" :key="r.id">
                <Link
                    :href="requestShow(r.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-36 font-medium">{{
                        r.number ?? t('purchases.requests.status.draft')
                    }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        r.author
                    }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ r.warehouse.name }} ·
                        {{
                            t('purchases.requests.lines_count', {
                                count: r.lines_count,
                            })
                        }}
                        · {{ formatDate(r.created_at) }}</span
                    >
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="badge[r.status]"
                        >{{ t(`purchases.requests.status.${r.status}`) }}</span
                    >
                </Link>
            </li>
        </ul>
        <Pager :prev="requests.prev_page_url" :next="requests.next_page_url" />
    </div>
</template>
