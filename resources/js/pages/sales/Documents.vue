<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, ShoppingCart } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pager from '@/components/Pager.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { create as posCreate } from '@/routes/pos';
import {
    create as salesCreate,
    index as salesIndex,
    show as salesShow,
} from '@/routes/sales';
import type { Paginated } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

type DocType = 'quote' | 'order' | 'invoice' | 'credit_note';
type SalesDoc = {
    id: string;
    type: DocType;
    number: string | null;
    status: 'draft' | 'validated' | 'converted' | 'cancelled';
    channel: 'pos' | 'backoffice';
    payment_status: 'unpaid' | 'partial' | 'paid' | 'refund_due' | null;
    customer: { name: string | null } | null;
    warehouse: { name: string };
    total: number;
    balance: number;
    created_at: string | null;
    validated_at: string | null;
};

const props = defineProps<{
    documents: Paginated<SalesDoc>;
    filters: { type: DocType; status: string; q: string };
    can: { create: boolean; pos: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('sales.title'), href: salesIndex() }],
    },
});

const types: DocType[] = ['invoice', 'quote', 'order', 'credit_note'];
const q = ref(props.filters.q);

function filter(changes: Partial<typeof props.filters>): void {
    router.get(
        salesIndex({ query: { ...props.filters, q: q.value, ...changes } }).url,
        {},
        { preserveState: true, replace: true },
    );
}

const statusBadge: Record<SalesDoc['status'], string> = {
    draft: 'bg-muted text-foreground',
    validated: 'bg-success-soft text-success-soft-foreground',
    converted: 'bg-info-soft text-info-soft-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
const paymentBadge: Record<string, string> = {
    unpaid: 'bg-warning-soft text-warning-soft-foreground',
    partial: 'bg-warning-soft text-warning-soft-foreground',
    paid: 'bg-success-soft text-success-soft-foreground',
    refund_due: 'bg-danger-soft text-danger-soft-foreground',
};
</script>

<template>
    <Head :title="t('sales.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('sales.title')"
                :description="t('sales.description')"
            />
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.pos" as-child class="h-11"
                    ><Link :href="posCreate()"
                        ><ShoppingCart class="size-4" />{{
                            t('sales.pos')
                        }}</Link
                    ></Button
                >
                <Button
                    v-if="can.create && filters.type !== 'credit_note'"
                    as-child
                    variant="outline"
                    class="h-11"
                    ><Link :href="salesCreate(filters.type)"
                        ><Plus class="size-4" />{{
                            t(
                                `sales.new_${filters.type as 'quote' | 'order' | 'invoice'}`,
                            )
                        }}</Link
                    ></Button
                >
            </div>
        </div>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="type in types"
                :key="type"
                role="tab"
                :aria-selected="filters.type === type"
                :variant="filters.type === type ? 'default' : 'outline'"
                class="h-11"
                @click="filter({ type, status: '' })"
                >{{ t(`sales.types_plural.${type}`) }}</Button
            >
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="filter({})">
            <Input
                v-model="q"
                type="search"
                class="h-11 max-w-xs"
                :placeholder="t('sales.search')"
                :aria-label="t('sales.search')"
            />
            <select
                :value="filters.status"
                class="h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                :aria-label="t('sales.filter_status')"
                @change="
                    filter({
                        status: ($event.target as HTMLSelectElement).value,
                    })
                "
            >
                <option value="">{{ t('sales.all') }}</option>
                <option v-if="filters.type === 'invoice'" value="unpaid">
                    {{ t('sales.unpaid') }}
                </option>
                <option value="draft">{{ t('sales.status.draft') }}</option>
                <option value="validated">
                    {{ t('sales.status.validated') }}
                </option>
                <option
                    v-if="filters.type === 'quote' || filters.type === 'order'"
                    value="converted"
                >
                    {{ t('sales.status.converted') }}
                </option>
                <option value="cancelled">
                    {{ t('sales.status.cancelled') }}
                </option>
            </select>
        </form>

        <EmptyState v-if="documents.data.length === 0" :hint="t('empty.sales')">
            {{ t('sales.empty') }}
            <template v-if="can.pos || can.create" #action>
                <div class="flex flex-wrap justify-center gap-2">
                    <Button
                        v-if="can.pos && filters.type === 'invoice'"
                        as-child
                        class="h-11"
                    >
                        <Link :href="posCreate()"
                            ><ShoppingCart class="size-4" />{{
                                t('sales.pos')
                            }}</Link
                        >
                    </Button>
                    <Button
                        v-if="can.create && filters.type !== 'credit_note'"
                        as-child
                        :variant="
                            can.pos && filters.type === 'invoice'
                                ? 'outline'
                                : 'default'
                        "
                        class="h-11"
                    >
                        <Link :href="salesCreate(filters.type)"
                            ><Plus class="size-4" />{{
                                t(
                                    `sales.new_${filters.type as 'quote' | 'order' | 'invoice'}`,
                                )
                            }}</Link
                        >
                    </Button>
                </div>
            </template>
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="doc in documents.data" :key="doc.id">
                <Link
                    :href="salesShow(doc.id)"
                    class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-1 p-3 hover:bg-accent"
                >
                    <span class="min-w-36 font-medium">{{
                        doc.number ??
                        t('sales.draft_title', {
                            type: t(`sales.types.${doc.type}`),
                        })
                    }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        doc.customer?.name ?? t('sales.walk_in')
                    }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ formatDate(doc.validated_at ?? doc.created_at) }}
                        <template v-if="doc.channel === 'pos'">
                            · {{ t('sales.pos_channel') }}</template
                        ></span
                    >
                    <span class="font-semibold tabular-nums">{{
                        formatXof(doc.total)
                    }}</span>
                    <span
                        v-if="doc.payment_status && doc.status === 'validated'"
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="paymentBadge[doc.payment_status]"
                        >{{
                            t(`sales.payment_status.${doc.payment_status}`)
                        }}</span
                    >
                    <span
                        v-else
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="statusBadge[doc.status]"
                        >{{ t(`sales.status.${doc.status}`) }}</span
                    >
                </Link>
            </li>
        </ul>

        <Pager
            :prev="documents.prev_page_url"
            :next="documents.next_page_url"
        />
    </div>
</template>
