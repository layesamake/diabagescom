<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import {
    currentPrices,
    discard,
    listCommands,
    resend,
    saleTotal,
    syncNow,
    syncState,
    type LocalCommand,
} from '@/offline/sync';
import { show as salesShow } from '@/routes/sales';
import { index as syncIndex } from '@/routes/sync';

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('offline.title'), href: syncIndex() }],
    },
});

const commands = ref<LocalCommand[]>([]);
const visible = computed(() =>
    commands.value.filter((c) => c.status !== 'discarded'),
);
const dateTime = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

async function refresh(): Promise<void> {
    commands.value = await listCommands();
}

onMounted(refresh);
watch(
    () => [syncState.pending, syncState.attention, syncState.syncing],
    refresh,
);

async function syncAgain(): Promise<void> {
    await syncNow();
    await refresh();
}

type Issue = {
    kind: 'price' | 'archived';
    name: string | null;
    captured?: number;
    current?: number;
};

function issues(command: LocalCommand): Issue[] {
    return (command.result?.issues ?? []) as Issue[];
}

async function resendWithCurrentPrices(command: LocalCommand): Promise<void> {
    const prices = await currentPrices(
        command.payload.lines.map((l) => l.product_id),
    );
    const lines = command.payload.lines.map((l) => ({
        ...l,
        unit_price: prices.get(l.product_id) ?? l.unit_price,
    }));
    // Les paiements ne dépassent jamais le nouveau total ; un reste éventuel
    // passe à crédit (le serveur exige alors un client avec plafond).
    let remaining = saleTotal({ ...command.payload, lines });
    const tenders = command.payload.tenders
        .map((tender) => {
            const amount = Math.min(tender.amount, remaining);
            remaining -= amount;

            return { ...tender, amount };
        })
        .filter((tender) => tender.amount > 0);
    await resend(command, { ...command.payload, lines, tenders });
    await refresh();
}

async function resendNegative(command: LocalCommand): Promise<void> {
    const reason = window.prompt(t('offline.negative_prompt'))?.trim();

    if (reason) {
        await resend(command, {
            ...command.payload,
            negative_stock_reason: reason,
        });
        await refresh();
    }
}

async function abandon(command: LocalCommand): Promise<void> {
    if (window.confirm(t('offline.discard_confirm'))) {
        await discard(command);
        await refresh();
    }
}

const badge: Record<string, string> = {
    queued: 'bg-info-soft text-info-soft-foreground',
    sending: 'bg-info-soft text-info-soft-foreground',
    accepted: 'bg-success-soft text-success-soft-foreground',
    rejected: 'bg-danger-soft text-danger-soft-foreground',
    conflict: 'bg-warning-soft text-warning-soft-foreground',
    requires_review: 'bg-warning-soft text-warning-soft-foreground',
};
</script>

<template>
    <Head :title="t('offline.title')" />

    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="t('offline.title')"
                :description="t('offline.description')"
            />
            <Button
                class="h-11"
                :disabled="!syncState.online || syncState.syncing"
                @click="syncAgain"
                ><RefreshCw
                    class="size-4"
                    :class="syncState.syncing ? 'animate-spin' : ''"
                />{{ t('offline.sync_now') }}</Button
            >
        </div>

        <p class="text-sm text-muted-foreground">
            {{
                syncState.lastSync
                    ? t('offline.last_sync', {
                          date: dateTime.format(new Date(syncState.lastSync)),
                      })
                    : t('offline.never_synced')
            }}
        </p>

        <p
            v-if="syncState.problem"
            class="rounded-lg border border-warning-border bg-warning-soft p-3 text-sm"
            role="alert"
        >
            {{ t(`offline.problem.${syncState.problem}`) }}
        </p>

        <p
            v-if="visible.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('offline.empty') }}
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="command in visible"
                :key="command.command_id"
                class="space-y-2 p-3"
            >
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="font-medium tabular-nums">{{
                        formatXof(command.summary.total)
                    }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        command.summary.labels.join(', ')
                    }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        dateTime.format(new Date(command.created_at))
                    }}</span>
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="badge[command.status]"
                        >{{ t(`offline.status.${command.status}`) }}</span
                    >
                </div>
                <p v-if="command.summary.customer" class="text-sm">
                    {{ command.summary.customer }}
                </p>
                <p
                    v-if="command.error && command.status !== 'accepted'"
                    class="text-sm text-destructive"
                >
                    {{ command.error.message }}
                </p>
                <ul v-if="issues(command).length" class="text-sm">
                    <li v-for="(issue, index) in issues(command)" :key="index">
                        {{
                            issue.kind === 'price'
                                ? t('offline.price_issue', {
                                      name: issue.name ?? '',
                                      captured: formatXof(issue.captured ?? 0),
                                      current: formatXof(issue.current ?? 0),
                                  })
                                : t('offline.archived_issue', {
                                      name: issue.name ?? '',
                                  })
                        }}
                    </li>
                </ul>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="
                            command.status === 'accepted' &&
                            command.result?.document_id
                        "
                        as-child
                        variant="outline"
                        class="h-11"
                        ><Link :href="salesShow(command.result.document_id)">{{
                            t('offline.open_document', {
                                number: command.result.number ?? '',
                            })
                        }}</Link></Button
                    >
                    <Button
                        v-if="
                            command.status === 'requires_review' &&
                            command.error?.code === 'price_changed'
                        "
                        class="h-11"
                        @click="resendWithCurrentPrices(command)"
                        >{{ t('offline.resend_prices') }}</Button
                    >
                    <Button
                        v-if="command.status === 'conflict'"
                        variant="outline"
                        class="h-11"
                        @click="resendNegative(command)"
                        >{{ t('offline.resend_negative') }}</Button
                    >
                    <Button
                        v-if="
                            [
                                'rejected',
                                'conflict',
                                'requires_review',
                            ].includes(command.status)
                        "
                        variant="ghost"
                        class="h-11"
                        @click="abandon(command)"
                        >{{ t('offline.discard') }}</Button
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
