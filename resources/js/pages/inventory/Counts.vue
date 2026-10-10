<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import {
    index as countsIndex,
    show as countShow,
    store as countStore,
} from '@/routes/counts';
import { index as stockIndex } from '@/routes/stock';
import type { Paginated } from '@/types';

type Count = {
    id: string;
    number: string | null;
    status: 'draft' | 'validated' | 'cancelled';
    warehouse: { code: string; name: string };
    lines_count: number;
    created_at: string | null;
};

const props = defineProps<{
    counts: Paginated<Count>;
    warehouses: { id: string; code: string; name: string }[];
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.counts'), href: countsIndex() },
        ],
    },
});

const warehouse = ref(props.warehouses[0]?.id ?? '');

function start(): void {
    router.post(countStore().url, { warehouse_id: warehouse.value });
}
</script>

<template>
    <Head :title="t('counts.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('counts.title')"
            :description="t('counts.description')"
        />

        <div
            v-if="canCreate"
            class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
        >
            <div class="grid gap-1">
                <Label for="warehouse">{{ t('stock.warehouse') }}</Label>
                <select
                    id="warehouse"
                    v-model="warehouse"
                    class="flex h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }} ({{ w.code }})
                    </option>
                </select>
            </div>
            <Button class="h-11" :disabled="!warehouse" @click="start"
                ><ClipboardList class="size-4" />{{ t('counts.start') }}</Button
            >
        </div>

        <p
            v-if="counts.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('counts.empty') }}
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="c in counts.data" :key="c.id">
                <Link
                    :href="countShow(c.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-32 font-medium">{{
                        c.number ?? t('counts.status.draft')
                    }}</span>
                    <span class="flex-1 text-sm">{{ c.warehouse.name }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ t('counts.lines', { count: c.lines_count }) }} ·
                        {{ formatDate(c.created_at) }}</span
                    >
                    <span
                        class="rounded bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{ t(`counts.status.${c.status}`) }}</span
                    >
                </Link>
            </li>
        </ul>
    </div>
</template>
