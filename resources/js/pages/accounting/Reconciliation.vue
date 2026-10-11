<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import { index as accountingIndex } from '@/routes/accounting';
import {
    complete as completeRoute,
    index as reconciliationsIndex,
    lines as linesRoute,
    store as reconciliationStore,
} from '@/routes/accounting/reconciliations';

const props = defineProps<{
    accounts: { code: string; name: string }[];
    account: { code: string; name: string } | null;
    book_balance: number;
    current: {
        id: string;
        statement_date: string;
        statement_balance: number;
        reference: string | null;
        reconciled: number;
    } | null;
    lines: {
        id: string;
        date: string;
        number: string;
        label: string;
        debit: number;
        credit: number;
        checked: boolean;
    }[];
    history: {
        id: string;
        statement_date: string;
        statement_balance: number;
        reference: string | null;
    }[];
    can: { reconcile: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
            {
                title: t('accounting.reconciliation'),
                href: reconciliationsIndex(),
            },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const form = reactive({
    statement_date: new Date().toISOString().slice(0, 10),
    statement_balance: '',
    reference: '',
});
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};
const difference = computed(() =>
    props.current
        ? props.current.statement_balance - props.current.reconciled
        : 0,
);

function selectAccount(code: string): void {
    router.get(reconciliationsIndex({ query: { account: code } }).url);
}

function start(): void {
    // Solde signé : un découvert se saisit avec un signe moins.
    const balance = Number.parseInt(
        form.statement_balance.replace(/[\s  ]/g, ''),
        10,
    );

    router.post(
        reconciliationStore().url,
        {
            account: props.account?.code,
            statement_date: form.statement_date,
            statement_balance: Number.isNaN(balance) ? null : balance,
            reference: form.reference || null,
        },
        options,
    );
}

function toggle(line: { id: string; checked: boolean }): void {
    if (props.current) {
        router.post(
            linesRoute(props.current.id).url,
            { lines: [line.id], checked: !line.checked },
            options,
        );
    }
}
</script>

<template>
    <Head :title="t('accounting.reconciliation')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Heading :title="t('accounting.reconciliation')" />

        <p v-if="accounts.length === 0" class="text-sm text-muted-foreground">
            {{ t('accounting.no_accounts') }}
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <select
                    :value="account?.code"
                    class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                    :aria-label="t('accounting.account')"
                    @change="
                        selectAccount(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="a in accounts" :key="a.code" :value="a.code">
                        {{ a.code }} — {{ a.name }}
                    </option>
                </select>
                <span class="text-sm">{{
                    t('accounting.book_balance', {
                        amount: formatXof(book_balance),
                    })
                }}</span>
            </div>

            <section
                v-if="!current && can.reconcile"
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[10rem_12rem_1fr_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label for="st-date">{{
                        t('accounting.statement_date')
                    }}</Label>
                    <Input
                        id="st-date"
                        v-model="form.statement_date"
                        type="date"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="st-balance">{{
                        t('accounting.statement_balance')
                    }}</Label>
                    <Input
                        id="st-balance"
                        v-model="form.statement_balance"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="st-ref">{{ t('accounting.reference') }}</Label>
                    <Input
                        id="st-ref"
                        v-model="form.reference"
                        maxlength="64"
                        class="h-11"
                    />
                </div>
                <Button class="h-11" :disabled="processing" @click="start">{{
                    t('accounting.start_reconciliation')
                }}</Button>
                <InputError
                    :message="errors.statement_balance ?? errors.business"
                    class="sm:col-span-4"
                />
            </section>

            <section v-if="current" class="space-y-3">
                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border p-4 text-sm"
                >
                    <span
                        >{{ t('accounting.statement_date') }} :
                        {{ formatDate(current.statement_date) }}</span
                    >
                    <span
                        >{{ t('accounting.statement_balance') }} :
                        <strong class="tabular-nums">{{
                            formatXof(current.statement_balance)
                        }}</strong></span
                    >
                    <span>{{
                        t('accounting.reconciled', {
                            amount: formatXof(current.reconciled),
                        })
                    }}</span>
                    <span
                        :class="
                            difference
                                ? 'font-medium text-destructive'
                                : 'font-medium text-emerald-700 dark:text-emerald-400'
                        "
                        >{{
                            t('accounting.to_reconcile', {
                                amount: formatXof(difference),
                            })
                        }}</span
                    >
                </div>
                <ul class="divide-y rounded-lg border">
                    <li v-for="line in lines" :key="line.id">
                        <label
                            class="flex min-h-11 cursor-pointer flex-wrap items-center gap-3 p-2 text-sm hover:bg-accent"
                        >
                            <input
                                type="checkbox"
                                class="size-5"
                                :checked="line.checked"
                                :disabled="!can.reconcile || processing"
                                @change="toggle(line)"
                            />
                            <span class="text-muted-foreground">{{
                                formatDate(line.date)
                            }}</span>
                            <span class="font-medium">{{ line.number }}</span>
                            <span class="min-w-0 flex-1 truncate">{{
                                line.label
                            }}</span>
                            <span class="tabular-nums">{{
                                line.debit
                                    ? '+ ' + formatXof(line.debit)
                                    : '− ' + formatXof(line.credit)
                            }}</span>
                        </label>
                    </li>
                </ul>
                <InputError :message="errors.business" />
                <Button
                    v-if="can.reconcile"
                    class="h-11"
                    :disabled="processing || difference !== 0"
                    @click="
                        router.post(completeRoute(current.id).url, {}, options)
                    "
                    >{{ t('accounting.complete') }}</Button
                >
            </section>

            <section v-if="history.length" class="space-y-2">
                <h2 class="font-semibold">{{ t('accounting.history') }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="h in history"
                        :key="h.id"
                        class="flex flex-wrap gap-3 p-2"
                    >
                        <span>{{ formatDate(h.statement_date) }}</span>
                        <span class="flex-1">{{ h.reference }}</span>
                        <span class="tabular-nums">{{
                            formatXof(h.statement_balance)
                        }}</span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
