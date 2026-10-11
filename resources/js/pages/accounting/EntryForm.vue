<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { formatXof, parseAmount } from '@/lib/money';
import { index as accountingIndex } from '@/routes/accounting';
import {
    index as entriesIndex,
    show as entryShow,
    store as entryStore,
    update as entryUpdate,
} from '@/routes/accounting/entries';

type Line = { account: string; label: string; debit: string; credit: string };

const props = defineProps<{
    entry: {
        id: string;
        journal: string;
        entry_date: string;
        description: string;
        lines: {
            account: string;
            label: string;
            debit: number;
            credit: number;
        }[];
    } | null;
    journals: { code: string; name: string }[];
    accounts: { code: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
            { title: t('accounting.entries'), href: entriesIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const journal = ref(
    props.entry?.journal ??
        props.journals.find((j) => j.code === 'OD')?.code ??
        props.journals[0]?.code ??
        '',
);
const entryDate = ref(
    props.entry?.entry_date ?? new Date().toISOString().slice(0, 10),
);
const description = ref(props.entry?.description ?? '');
const lines = ref<Line[]>(
    props.entry?.lines.map((l) => ({
        account: l.account,
        label: l.label,
        debit: l.debit ? String(l.debit) : '',
        credit: l.credit ? String(l.credit) : '',
    })) ?? [
        { account: '', label: '', debit: '', credit: '' },
        { account: '', label: '', debit: '', credit: '' },
    ],
);
const processing = ref(false);
const totals = computed(() => ({
    debit: lines.value.reduce((s, l) => s + parseAmount(l.debit), 0),
    credit: lines.value.reduce((s, l) => s + parseAmount(l.credit), 0),
}));
const selectClass =
    'h-11 w-full rounded-md border border-input bg-transparent px-2 text-sm';

function submit(): void {
    const payload = {
        journal: journal.value,
        entry_date: entryDate.value,
        description: description.value,
        lines: lines.value.map((l) => ({
            account: l.account,
            label: l.label || null,
            debit: parseAmount(l.debit),
            credit: parseAmount(l.credit),
        })),
    };
    const options = {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    if (props.entry) {
        router.put(entryUpdate(props.entry.id).url, payload, options);
    } else {
        router.post(entryStore().url, payload, options);
    }
}
</script>

<template>
    <Head :title="t('accounting.new_entry')" />

    <div class="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
        <Link
            :href="entry ? entryShow(entry.id) : entriesIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('accounting.back')
            }}
        </Link>
        <Heading
            :title="
                entry ? t('accounting.draft_title') : t('accounting.new_entry')
            "
        />

        <div class="grid gap-4 sm:grid-cols-[10rem_10rem_1fr]">
            <div class="grid gap-1">
                <Label for="journal">{{ t('accounting.journal') }}</Label>
                <select id="journal" v-model="journal" :class="selectClass">
                    <option v-for="j in journals" :key="j.code" :value="j.code">
                        {{ j.code }} — {{ j.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="date">{{ t('accounting.date') }}</Label>
                <Input id="date" v-model="entryDate" type="date" class="h-11" />
            </div>
            <div class="grid gap-1">
                <Label for="description">{{ t('accounting.label') }}</Label>
                <Input
                    id="description"
                    v-model="description"
                    maxlength="255"
                    class="h-11"
                />
                <InputError :message="errors.description" />
            </div>
        </div>

        <datalist id="accounts">
            <option v-for="a in accounts" :key="a.code" :value="a.code">
                {{ a.name }}
            </option>
        </datalist>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="(line, index) in lines"
                :key="index"
                class="grid gap-2 p-3 sm:grid-cols-[8rem_1fr_8rem_8rem_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label :for="`acc-${index}`" class="text-xs">{{
                        t('accounting.account')
                    }}</Label>
                    <Input
                        :id="`acc-${index}`"
                        v-model="line.account"
                        list="accounts"
                        class="h-11 tabular-nums"
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`lbl-${index}`" class="text-xs">{{
                        t('accounting.label')
                    }}</Label>
                    <Input
                        :id="`lbl-${index}`"
                        v-model="line.label"
                        maxlength="255"
                        class="h-11"
                        :placeholder="
                            accounts.find((a) => a.code === line.account)
                                ?.name ?? ''
                        "
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`d-${index}`" class="text-xs">{{
                        t('accounting.debit')
                    }}</Label>
                    <Input
                        :id="`d-${index}`"
                        v-model="line.debit"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                        @input="line.debit && (line.credit = '')"
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`c-${index}`" class="text-xs">{{
                        t('accounting.credit')
                    }}</Label>
                    <Input
                        :id="`c-${index}`"
                        v-model="line.credit"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                        @input="line.credit && (line.debit = '')"
                    />
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-11"
                    :aria-label="
                        t('sales.remove_line', {
                            name: line.account || String(index + 1),
                        })
                    "
                    :disabled="lines.length <= 2"
                    @click="lines.splice(index, 1)"
                    ><X class="size-4"
                /></Button>
            </li>
        </ul>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button
                variant="outline"
                class="h-11"
                @click="
                    lines.push({
                        account: '',
                        label: '',
                        debit: '',
                        credit: '',
                    })
                "
                ><Plus class="size-4" />{{ t('accounting.add_line') }}</Button
            >
            <p class="text-sm tabular-nums">
                {{ t('accounting.totals') }} : {{ formatXof(totals.debit) }} /
                {{ formatXof(totals.credit) }}
                <span
                    v-if="totals.debit !== totals.credit"
                    class="ml-2 font-medium text-destructive"
                    >{{ t('accounting.difference') }}
                    {{ formatXof(totals.debit - totals.credit) }}</span
                >
            </p>
        </div>

        <InputError :message="errors.business ?? errors.lines" />

        <Button
            class="h-11 w-full sm:w-fit"
            :disabled="processing || !description"
            @click="submit"
        >
            <Spinner v-if="processing" />{{ t('accounting.save_draft') }}
        </Button>
    </div>
</template>
