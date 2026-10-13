<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import {
    index as accountingIndex,
    reports as reportsRoute,
} from '@/routes/accounting';
import { show as entryShow } from '@/routes/accounting/entries';

type ReportType =
    | 'balance'
    | 'ledger'
    | 'customers'
    | 'suppliers'
    | 'balance_sheet'
    | 'income_statement';
type Statement = {
    tables: {
        title: string;
        rows: {
            label: string;
            amount: number;
            kind: 'line' | 'subtotal' | 'total';
            accounts: string;
        }[];
    }[];
    balanced: boolean;
    difference: number;
    result: number;
};
type BalanceRow = {
    account_id: string;
    code: string;
    name: string;
    opening: number;
    debit: number;
    credit: number;
    closing: number;
};
type PartyRow = {
    party_id: string;
    code: string;
    name: string;
    debit: number;
    credit: number;
    balance: number;
};
type Ledger = {
    opening: number;
    closing: number;
    lines: {
        id: string;
        entry_id: string;
        date: string;
        journal: string;
        number: string;
        label: string;
        debit: number;
        credit: number;
        balance: number;
    }[];
};

const props = defineProps<{
    report: ReportType;
    filters: { from: string; to: string; account: string };
    data: BalanceRow[] | PartyRow[] | Ledger | Statement | null;
    accounts: { code: string; name: string }[];
    can: { export: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
            { title: t('accounting.reports'), href: reportsRoute() },
        ],
    },
});

const form = reactive({ ...props.filters });
const types: ReportType[] = [
    'balance_sheet',
    'income_statement',
    'balance',
    'ledger',
    'customers',
    'suppliers',
];
const isStatement = computed(() =>
    ['balance_sheet', 'income_statement'].includes(props.report),
);
const statement = computed(() =>
    isStatement.value ? (props.data as Statement | null) : null,
);
const balance = computed(() =>
    props.report === 'balance' ? (props.data as BalanceRow[]) : [],
);
const parties = computed(() =>
    ['customers', 'suppliers'].includes(props.report)
        ? (props.data as PartyRow[])
        : [],
);
const ledger = computed(() =>
    props.report === 'ledger' ? (props.data as Ledger | null) : null,
);
const sums = computed(() => ({
    debit: balance.value.reduce((s, r) => s + r.debit, 0),
    credit: balance.value.reduce((s, r) => s + r.credit, 0),
}));

function load(report: ReportType = props.report): void {
    router.get(
        reportsRoute({ query: { report, ...form } }).url,
        {},
        { preserveState: true },
    );
}

const csvUrl = computed(
    () =>
        reportsRoute({
            query: { report: props.report, ...props.filters, format: 'csv' },
        }).url,
);
const cell = 'p-2 text-right tabular-nums';
</script>

<template>
    <Head :title="t(`accounting.report_types.${report}`)" />

    <div class="space-y-6 p-4 md:p-6">
        <Heading :title="t('accounting.reports')" />

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="type in types"
                :key="type"
                role="tab"
                :aria-selected="report === type"
                :variant="report === type ? 'default' : 'outline'"
                class="h-11"
                @click="load(type)"
                >{{ t(`accounting.report_types.${type}`) }}</Button
            >
        </div>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="load()">
            <Input
                v-if="
                    report === 'balance' ||
                    report === 'ledger' ||
                    report === 'income_statement'
                "
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
            <select
                v-if="report === 'ledger'"
                v-model="form.account"
                class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                :aria-label="t('accounting.account')"
            >
                <option value="">{{ t('accounting.choose_account') }}</option>
                <option v-for="a in accounts" :key="a.code" :value="a.code">
                    {{ a.code }} — {{ a.name }}
                </option>
            </select>
            <Button type="submit" variant="outline" class="h-11">{{
                t('accounting.filter')
            }}</Button>
            <Button
                v-if="can.export && data"
                as-child
                variant="ghost"
                class="h-11"
                ><a :href="csvUrl"
                    ><Download class="size-4" />{{
                        t('accounting.export_csv')
                    }}</a
                ></Button
            >
        </form>

        <!-- États de synthèse : bilan et compte de résultat -->
        <div v-if="isStatement" class="space-y-4">
            <p
                v-if="!statement"
                class="rounded-lg border p-6 text-sm text-muted-foreground"
            >
                {{ t('accounting.statements.no_year') }}
            </p>
            <template v-else>
                <p
                    v-if="!statement.balanced"
                    class="rounded-lg border border-warning-border bg-warning-soft p-3 text-sm text-warning-soft-foreground"
                    role="alert"
                >
                    {{
                        t('accounting.statements.unbalanced', {
                            amount: formatXof(Math.abs(statement.difference)),
                        })
                    }}
                </p>
                <div
                    v-for="table in statement.tables"
                    :key="table.title"
                    class="overflow-x-auto rounded-lg border"
                >
                    <table class="w-full min-w-[28rem] text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="p-2">{{ table.title }}</th>
                                <th class="p-2 text-muted-foreground">
                                    {{ t('accounting.statements.accounts') }}
                                </th>
                                <th class="p-2 text-right">
                                    {{ t('accounting.statements.amount') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in table.rows"
                                :key="line.label"
                                class="border-t"
                                :class="{
                                    'bg-muted/30 font-semibold':
                                        line.kind === 'subtotal',
                                    'bg-muted/60 font-bold':
                                        line.kind === 'total',
                                }"
                            >
                                <td class="p-2">{{ line.label }}</td>
                                <td
                                    class="p-2 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ line.accounts }}
                                </td>
                                <td :class="cell">
                                    {{ formatXof(line.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ t('accounting.statements.disclaimer') }}
                </p>
            </template>
        </div>

        <!-- Balance générale -->
        <div
            v-else-if="report === 'balance'"
            class="overflow-x-auto rounded-lg border"
        >
            <p
                v-if="balance.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                {{ t('accounting.no_data') }}
            </p>
            <table v-else class="w-full min-w-[40rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">{{ t('accounting.account') }}</th>
                        <th class="p-2 text-right">
                            {{ t('accounting.opening') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.debit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.credit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.closing') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in balance"
                        :key="row.account_id"
                        class="border-t"
                    >
                        <td class="p-2">
                            <Link
                                :href="
                                    reportsRoute({
                                        query: {
                                            report: 'ledger',
                                            account: row.code,
                                            from: filters.from,
                                            to: filters.to,
                                        },
                                    })
                                "
                                class="underline-offset-4 hover:underline"
                                ><span class="font-medium tabular-nums">{{
                                    row.code
                                }}</span>
                                {{ row.name }}</Link
                            >
                        </td>
                        <td :class="cell">{{ formatXof(row.opening) }}</td>
                        <td :class="cell">{{ formatXof(row.debit) }}</td>
                        <td :class="cell">{{ formatXof(row.credit) }}</td>
                        <td :class="cell">{{ formatXof(row.closing) }}</td>
                    </tr>
                    <tr class="border-t font-semibold">
                        <td class="p-2" colspan="2">
                            {{ t('accounting.totals') }}
                        </td>
                        <td :class="cell">{{ formatXof(sums.debit) }}</td>
                        <td :class="cell">{{ formatXof(sums.credit) }}</td>
                        <td />
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Grand livre -->
        <div
            v-else-if="report === 'ledger'"
            class="overflow-x-auto rounded-lg border"
        >
            <p v-if="!ledger" class="p-6 text-sm text-muted-foreground">
                {{ t('accounting.choose_account') }}
            </p>
            <table v-else class="w-full min-w-[44rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">{{ t('accounting.date') }}</th>
                        <th class="p-2">{{ t('accounting.journal') }}</th>
                        <th class="p-2">{{ t('accounting.label') }}</th>
                        <th class="p-2 text-right">
                            {{ t('accounting.debit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.credit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.balance') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t text-muted-foreground">
                        <td class="p-2" colspan="5">
                            {{ t('accounting.opening') }}
                        </td>
                        <td :class="cell">{{ formatXof(ledger.opening) }}</td>
                    </tr>
                    <tr
                        v-for="line in ledger.lines"
                        :key="line.id"
                        class="border-t"
                    >
                        <td class="p-2">{{ formatDate(line.date) }}</td>
                        <td class="p-2">
                            <Link
                                :href="entryShow(line.entry_id)"
                                class="underline-offset-4 hover:underline"
                                >{{ line.number }}</Link
                            >
                        </td>
                        <td class="p-2">{{ line.label }}</td>
                        <td :class="cell">
                            {{ line.debit ? formatXof(line.debit) : '' }}
                        </td>
                        <td :class="cell">
                            {{ line.credit ? formatXof(line.credit) : '' }}
                        </td>
                        <td :class="cell">{{ formatXof(line.balance) }}</td>
                    </tr>
                    <tr class="border-t font-semibold">
                        <td class="p-2" colspan="5">
                            {{ t('accounting.closing') }}
                        </td>
                        <td :class="cell">{{ formatXof(ledger.closing) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Balances auxiliaires -->
        <div v-else class="overflow-x-auto rounded-lg border">
            <p
                v-if="parties.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                {{ t('accounting.no_data') }}
            </p>
            <table v-else class="w-full min-w-[32rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">{{ t('accounting.party') }}</th>
                        <th class="p-2 text-right">
                            {{ t('accounting.debit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.credit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.balance') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in parties"
                        :key="row.party_id"
                        class="border-t"
                    >
                        <td class="p-2">
                            <span class="font-medium">{{ row.name }}</span>
                            <span class="text-muted-foreground">{{
                                row.code
                            }}</span>
                        </td>
                        <td :class="cell">{{ formatXof(row.debit) }}</td>
                        <td :class="cell">{{ formatXof(row.credit) }}</td>
                        <td :class="cell">{{ formatXof(row.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
