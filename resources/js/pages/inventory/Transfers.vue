<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { index as stockIndex } from '@/routes/stock';
import {
    create as transferCreate,
    index as transfersIndex,
    show as transferShow,
} from '@/routes/transfers';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type Transfer = {
    id: string;
    number: string | null;
    status: 'draft' | 'in_transit' | 'received' | 'cancelled';
    source: { code: string; name: string };
    destination: { code: string; name: string };
    lines_count: number;
    created_at: string | null;
};

defineProps<{ transfers: Paginated<Transfer>; canCreate: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.transfers'), href: transfersIndex() },
        ],
    },
});

const badge: Record<Transfer['status'], string> = {
    draft: 'bg-muted text-foreground',
    in_transit: 'bg-warning-soft text-warning-soft-foreground',
    received: 'bg-success-soft text-success-soft-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <Head :title="t('transfers.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('transfers.title')"
                :description="t('transfers.description')"
            />
            <Button v-if="canCreate" as-child class="h-11"
                ><Link :href="transferCreate()"
                    ><Plus class="size-4" />{{ t('transfers.new') }}</Link
                ></Button
            >
        </div>

        <EmptyState v-if="transfers.data.length === 0">
            {{ t('transfers.empty') }}
            <template v-if="canCreate" #action>
                <Button as-child class="h-11"
                    ><Link :href="transferCreate()"
                        ><Plus class="size-4" />{{ t('transfers.new') }}</Link
                    ></Button
                >
            </template>
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="tr in transfers.data" :key="tr.id">
                <Link
                    :href="transferShow(tr.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-32 font-medium">{{
                        tr.number ?? t('transfers.draft_label')
                    }}</span>
                    <span class="inline-flex flex-1 items-center gap-2 text-sm"
                        >{{ tr.source.name }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                        {{ tr.destination.name }}</span
                    >
                    <span class="text-sm text-muted-foreground"
                        >{{ t('transfers.lines', { count: tr.lines_count }) }} ·
                        {{ formatDate(tr.created_at) }}</span
                    >
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="badge[tr.status]"
                        >{{ t(`transfers.status.${tr.status}`) }}</span
                    >
                </Link>
            </li>
        </ul>

        <nav
            v-if="transfers.prev_page_url || transfers.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="transfers.prev_page_url"
                :href="transfers.prev_page_url"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="transfers.next_page_url"
                :href="transfers.next_page_url"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
