<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import Pager from '@/components/Pager.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { index as accountingIndex } from '@/routes/accounting';
import {
    create as entryCreate,
    index as entriesIndex,
    show as entryShow,
} from '@/routes/accounting/entries';
import type { Paginated } from '@/types';

type Entry = {
    id: string;
    number: string | null;
    journal: string;
    entry_date: string;
    description: string;
    status: 'draft' | 'posted';
    amount: number;
    automatic: boolean;
};

const props = defineProps<{
    entries: Paginated<Entry>;
    filters: {
        journal: string;
        status: string;
        from: string;
        to: string;
        q: string;
    };
    journals: { code: string; name: string }[];
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
            { title: t('accounting.entries'), href: entriesIndex() },
        ],
    },
});

const form = reactive({ ...props.filters });
const selectClass =
    'h-11 rounded-md border border-input bg-transparent px-3 text-sm';

function apply(): void {
    router.get(
        entriesIndex({ query: { ...form } }).url,
        {},
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head :title="t('accounting.entries')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="t('accounting.entries')" />
            <Button v-if="can.create" as-child class="h-11"
                ><Link :href="entryCreate()"
                    ><Plus class="size-4" />{{
                        t('accounting.new_entry')
                    }}</Link
                ></Button
            >
        </div>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <select
                v-model="form.journal"
                :class="selectClass"
                :aria-label="t('accounting.journal')"
            >
                <option value="">{{ t('accounting.all_journals') }}</option>
                <option v-for="j in journals" :key="j.code" :value="j.code">
                    {{ j.code }} — {{ j.name }}
                </option>
            </select>
            <select
                v-model="form.status"
                :class="selectClass"
                :aria-label="t('sales.filter_status')"
            >
                <option value="">{{ t('accounting.all') }}</option>
                <option value="posted">
                    {{ t('accounting.status.posted') }}
                </option>
                <option value="draft">
                    {{ t('accounting.status.draft') }}
                </option>
            </select>
            <Input
                v-model="form.from"
                type="date"
                class="h-11 w-40"
                :aria-label="t('accounting.from')"
            />
            <Input
                v-model="form.to"
                type="date"
                class="h-11 w-40"
                :aria-label="t('accounting.to')"
            />
            <Input
                v-model="form.q"
                type="search"
                class="h-11 w-48"
                :placeholder="t('accounting.search')"
                :aria-label="t('accounting.search')"
            />
            <Button type="submit" variant="outline" class="h-11">{{
                t('accounting.filter')
            }}</Button>
        </form>

        <p
            v-if="entries.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('accounting.empty') }}
        </p>
        <ul v-else class="divide-y rounded-lg border">
            <li v-for="entry in entries.data" :key="entry.id">
                <Link
                    :href="entryShow(entry.id)"
                    class="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm hover:bg-accent"
                >
                    <span class="min-w-36 font-medium">{{
                        entry.number ?? t('accounting.status.draft')
                    }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(entry.entry_date)
                    }}</span>
                    <span class="min-w-0 flex-1 truncate">{{
                        entry.description
                    }}</span>
                    <span
                        v-if="entry.automatic"
                        class="rounded bg-info-soft px-2 py-0.5 text-xs text-info-soft-foreground"
                        >{{ t('accounting.automatic') }}</span
                    >
                    <span
                        v-if="entry.status === 'draft'"
                        class="rounded bg-muted px-2 py-0.5 text-xs"
                        >{{ t('accounting.status.draft') }}</span
                    >
                    <span class="font-semibold tabular-nums">{{
                        formatXof(entry.amount)
                    }}</span>
                </Link>
            </li>
        </ul>
        <Pager :prev="entries.prev_page_url" :next="entries.next_page_url" />
    </div>
</template>
