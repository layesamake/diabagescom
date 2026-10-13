<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Paperclip,
    Pencil,
    Trash2,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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
    destroy as entryDestroy,
    edit as entryEdit,
    index as entriesIndex,
    post as postRoute,
    reverse as reverseRoute,
    show as entryShow,
} from '@/routes/accounting/entries';
import {
    destroy as attachmentDestroy,
    show as attachmentShow,
    store as attachmentStore,
} from '@/routes/accounting/entries/attachments';
import { confirmAction } from '@/composables/useConfirm';

const props = defineProps<{
    entry: {
        id: string;
        number: string | null;
        journal: { code: string; name: string };
        entry_date: string;
        description: string;
        status: 'draft' | 'posted';
        source: { type: string; id: string; kind: string } | null;
        reversal: { id: string; number: string | null } | null;
        reversal_of: { id: string; number: string | null } | null;
        lines: {
            id: string;
            account: string;
            account_name: string;
            label: string;
            debit: number;
            credit: number;
        }[];
        attachments: { id: string; name: string; mime: string; size: number }[];
    };
    can: {
        edit: boolean;
        post: boolean;
        reverse: boolean;
        attach: boolean;
        detach: boolean;
    };
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
const e = computed(() => props.entry);
const processing = ref(false);
const showReverse = ref(false);
const reverseDate = ref(new Date().toISOString().slice(0, 10));
const reason = ref('');
const totals = computed(() => ({
    debit: e.value.lines.reduce((s, l) => s + l.debit, 0),
    credit: e.value.lines.reduce((s, l) => s + l.credit, 0),
}));
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function upload(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file) {
        router.post(
            attachmentStore(e.value.id).url,
            { file },
            { ...options, forceFormData: true },
        );
    }

    input.value = '';
}

function kb(bytes: number): string {
    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} Mo`
        : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
}

async function post(): Promise<void> {
    if (
        await confirmAction({
            title: t('accounting.post_confirm'),
            confirmLabel: t('confirm.validate'),
            tone: 'primary',
        })
    ) {
        router.post(postRoute(e.value.id).url, {}, options);
    }
}
</script>

<template>
    <Head :title="e.number ?? t('accounting.draft_title')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="entriesIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('accounting.back')
            }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    e.number
                        ? t('accounting.entry_title', { number: e.number })
                        : t('accounting.draft_title')
                "
                :description="`${e.journal.code} — ${e.journal.name} · ${formatDate(e.entry_date)}`"
            />
            <span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{
                t(`accounting.status.${e.status}`)
            }}</span>
        </div>

        <p>{{ e.description }}</p>
        <p v-if="e.source" class="text-sm text-muted-foreground">
            {{
                t('accounting.source', {
                    type: `${e.source.type} · ${e.source.kind}`,
                })
            }}
        </p>
        <p v-if="e.reversal_of" class="text-sm">
            <Link
                :href="entryShow(e.reversal_of.id)"
                class="underline underline-offset-4"
                >{{
                    t('accounting.reversal_of', {
                        number: e.reversal_of.number ?? '',
                    })
                }}</Link
            >
        </p>
        <p v-if="e.reversal" class="text-sm">
            <Link
                :href="entryShow(e.reversal.id)"
                class="underline underline-offset-4"
                >{{
                    t('accounting.reversed_by', {
                        number: e.reversal.number ?? '',
                    })
                }}</Link
            >
        </p>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[32rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">{{ t('accounting.account') }}</th>
                        <th class="p-2">{{ t('accounting.label') }}</th>
                        <th class="p-2 text-right">
                            {{ t('accounting.debit') }}
                        </th>
                        <th class="p-2 text-right">
                            {{ t('accounting.credit') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in e.lines" :key="line.id" class="border-t">
                        <td class="p-2">
                            <span class="font-medium tabular-nums">{{
                                line.account
                            }}</span>
                            <span class="text-muted-foreground">{{
                                line.account_name
                            }}</span>
                        </td>
                        <td class="p-2">{{ line.label }}</td>
                        <td class="p-2 text-right tabular-nums">
                            {{ line.debit ? formatXof(line.debit) : '' }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ line.credit ? formatXof(line.credit) : '' }}
                        </td>
                    </tr>
                    <tr class="border-t font-semibold">
                        <td class="p-2" colspan="2">
                            {{ t('accounting.totals') }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ formatXof(totals.debit) }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ formatXof(totals.credit) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="space-y-2 rounded-lg border p-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Paperclip class="size-4" aria-hidden="true" />{{
                    t('accounting.attachments.title')
                }}
            </h2>
            <p
                v-if="e.attachments.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('accounting.attachments.empty') }}
            </p>
            <ul v-else class="divide-y text-sm">
                <li
                    v-for="a in e.attachments"
                    :key="a.id"
                    class="flex min-h-11 items-center justify-between gap-3"
                >
                    <a
                        :href="
                            attachmentShow({ entry: e.id, attachment: a.id })
                                .url
                        "
                        class="truncate underline underline-offset-4"
                        >{{ a.name }}</a
                    >
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-muted-foreground">{{
                            kb(a.size)
                        }}</span>
                        <Button
                            v-if="can.detach"
                            variant="ghost"
                            class="h-11"
                            :disabled="processing"
                            :aria-label="t('accounting.attachments.remove')"
                            @click="
                                router.delete(
                                    attachmentDestroy({
                                        entry: e.id,
                                        attachment: a.id,
                                    }).url,
                                    options,
                                )
                            "
                            ><Trash2 class="size-4"
                        /></Button>
                    </span>
                </li>
            </ul>
            <div v-if="can.attach" class="grid gap-1">
                <Label for="attachment">{{
                    t('accounting.attachments.add')
                }}</Label>
                <Input
                    id="attachment"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    class="h-11"
                    :disabled="processing"
                    @change="upload"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('accounting.attachments.hint') }}
                </p>
            </div>
            <InputError :message="errors.file" />
        </section>

        <InputError :message="errors.business ?? errors.reason" />

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.edit" as-child variant="outline" class="h-11"
                ><Link :href="entryEdit(e.id)"
                    ><Pencil class="size-4" />{{ t('accounting.edit') }}</Link
                ></Button
            >
            <Button
                v-if="can.post"
                class="h-11"
                :disabled="processing"
                @click="post"
                ><CheckCircle2 class="size-4" />{{
                    t('accounting.post')
                }}</Button
            >
            <Button
                v-if="can.edit"
                variant="ghost"
                class="h-11"
                :disabled="processing"
                @click="router.delete(entryDestroy(e.id).url, options)"
                ><Trash2 class="size-4" />{{ t('accounting.delete') }}</Button
            >
            <Button
                v-if="can.reverse"
                variant="outline"
                class="h-11"
                @click="showReverse = !showReverse"
                ><Undo2 class="size-4" />{{ t('accounting.reverse') }}</Button
            >
        </div>

        <section
            v-if="showReverse && can.reverse"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[10rem_1fr_auto] sm:items-end"
        >
            <div class="grid gap-1">
                <Label for="rev-date">{{ t('accounting.reverse_date') }}</Label>
                <Input
                    id="rev-date"
                    v-model="reverseDate"
                    type="date"
                    class="h-11"
                />
            </div>
            <div class="grid gap-1">
                <Label for="rev-reason">{{
                    t('accounting.reverse_reason')
                }}</Label>
                <Input
                    id="rev-reason"
                    v-model="reason"
                    maxlength="200"
                    class="h-11"
                />
            </div>
            <Button
                class="h-11"
                :disabled="processing || reason.trim().length < 3"
                @click="
                    router.post(
                        reverseRoute(e.id).url,
                        { date: reverseDate, reason },
                        options,
                    )
                "
                >{{ t('accounting.reverse') }}</Button
            >
        </section>
    </div>
</template>
