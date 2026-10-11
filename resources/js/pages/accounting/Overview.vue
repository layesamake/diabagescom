<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BookOpen, FileText, Landmark, ListTree, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import {
    expert as expertRoute,
    index as accountingIndex,
    post_pending as postPendingRoute,
    reports as reportsRoute,
    setup as setupRoute,
} from '@/routes/accounting';
import { index as accountsIndex } from '@/routes/accounting/accounts';
import { index as entriesIndex } from '@/routes/accounting/entries';
import { update as periodUpdate } from '@/routes/accounting/periods';
import { index as reconciliationsIndex } from '@/routes/accounting/reconciliations';
import {
    close as closeYearRoute,
    store as yearStore,
} from '@/routes/accounting/years';

type PeriodStatus = 'open' | 'locked' | 'closed';

defineProps<{
    settings: {
        posting_starts_on: string;
        expert_name: string | null;
        expert_validated_at: string | null;
    } | null;
    pending: number;
    entries: number;
    drafts: number;
    years: {
        id: string;
        name: string;
        starts_on: string;
        ends_on: string;
        status: 'open' | 'closed';
        periods: { id: string; starts_on: string; status: PeriodStatus }[];
    }[];
    can: { settings: boolean; post: boolean; close: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const today = new Date();
const fiscalYearStart = ref(`${today.getFullYear()}-01-01`);
const postingStartsOn = ref(today.toISOString().slice(0, 10));
const expertName = ref('');
const month = new Intl.DateTimeFormat('fr-FR', {
    month: 'short',
    year: 'numeric',
});

const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function setup(): void {
    router.post(
        setupRoute().url,
        {
            fiscal_year_start: fiscalYearStart.value,
            posting_starts_on: postingStartsOn.value,
        },
        options,
    );
}

function changePeriod(id: string, action: 'lock' | 'unlock' | 'close'): void {
    if (action === 'close' && !window.confirm(t('accounting.close_confirm'))) {
        return;
    }

    router.post(periodUpdate(id).url, { action }, options);
}

function closeYear(id: string, name: string): void {
    if (window.confirm(t('accounting.close_year_confirm', { name }))) {
        router.post(closeYearRoute(id).url, {}, options);
    }
}

const badge: Record<PeriodStatus, string> = {
    open: 'bg-emerald-100 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-50',
    locked: 'bg-amber-100 text-amber-950 dark:bg-amber-950 dark:text-amber-50',
    closed: 'bg-muted text-muted-foreground',
};
const links = [
    { href: entriesIndex(), label: t('accounting.entries'), icon: BookOpen },
    { href: reportsRoute(), label: t('accounting.reports'), icon: FileText },
    { href: accountsIndex(), label: t('accounting.accounts'), icon: ListTree },
    {
        href: reconciliationsIndex(),
        label: t('accounting.reconciliation'),
        icon: Landmark,
    },
];
</script>

<template>
    <Head :title="t('accounting.title')" />

    <div class="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            :title="t('accounting.title')"
            :description="t('accounting.description')"
        />

        <!-- Activation -->
        <section v-if="!settings" class="space-y-4 rounded-lg border p-4">
            <h2 class="font-semibold">{{ t('accounting.setup_title') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('accounting.setup_hint') }}
            </p>
            <div
                v-if="can.settings"
                class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label for="fy-start">{{
                        t('accounting.fiscal_year_start')
                    }}</Label>
                    <Input
                        id="fy-start"
                        v-model="fiscalYearStart"
                        type="date"
                        class="h-11"
                    />
                    <InputError :message="errors.fiscal_year_start" />
                </div>
                <div class="grid gap-1">
                    <Label for="posting">{{
                        t('accounting.posting_starts_on')
                    }}</Label>
                    <Input
                        id="posting"
                        v-model="postingStartsOn"
                        type="date"
                        class="h-11"
                    />
                    <InputError :message="errors.posting_starts_on" />
                </div>
                <Button class="h-11" :disabled="processing" @click="setup">{{
                    t('accounting.setup')
                }}</Button>
            </div>
            <InputError :message="errors.business" />
        </section>

        <template v-else>
            <!-- Validation de l'expert-comptable (docs/05) -->
            <div
                v-if="!settings.expert_validated_at"
                class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
                role="alert"
            >
                <p class="text-sm">{{ t('accounting.expert_missing') }}</p>
                <div v-if="can.settings" class="flex flex-wrap items-end gap-2">
                    <div class="grid gap-1">
                        <Label for="expert" class="text-xs">{{
                            t('accounting.expert_name')
                        }}</Label>
                        <Input
                            id="expert"
                            v-model="expertName"
                            maxlength="120"
                            class="h-11 bg-background"
                        />
                    </div>
                    <Button
                        class="h-11"
                        :disabled="processing || expertName.trim().length < 3"
                        @click="
                            router.post(
                                expertRoute().url,
                                { expert_name: expertName },
                                options,
                            )
                        "
                        >{{ t('accounting.expert_record') }}</Button
                    >
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    t('accounting.expert_done', {
                        name: settings.expert_name ?? '',
                        date: formatDate(settings.expert_validated_at),
                    })
                }}
            </p>

            <nav class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-lg border p-4 text-center font-medium hover:bg-accent"
                >
                    <component
                        :is="link.icon"
                        class="size-6"
                        aria-hidden="true"
                    />
                    {{ link.label }}
                </Link>
            </nav>

            <section
                class="flex flex-wrap items-center gap-3 rounded-lg border p-4"
            >
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-medium">
                        {{
                            pending > 0
                                ? t('accounting.pending', { count: pending })
                                : t('accounting.up_to_date')
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            t('accounting.posted_entries', {
                                count: entries,
                                drafts,
                            })
                        }}
                    </p>
                </div>
                <Button
                    v-if="can.post"
                    class="h-11"
                    :disabled="processing"
                    @click="router.post(postPendingRoute().url, {}, options)"
                    ><RefreshCw class="size-4" />{{
                        t('accounting.post_now')
                    }}</Button
                >
            </section>

            <InputError :message="errors.business" />

            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ t('accounting.years') }}</h2>
                    <Button
                        v-if="can.settings"
                        variant="outline"
                        class="h-11"
                        :disabled="processing"
                        @click="router.post(yearStore().url, {}, options)"
                        >{{ t('accounting.new_year') }}</Button
                    >
                </div>
                <article
                    v-for="year in years"
                    :key="year.id"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <p class="font-medium">
                            {{ year.name }} · {{ formatDate(year.starts_on) }} –
                            {{ formatDate(year.ends_on) }}
                            <span
                                class="ml-2 rounded bg-muted px-2 py-0.5 text-xs"
                                >{{
                                    t(`accounting.year_status.${year.status}`)
                                }}</span
                            >
                        </p>
                        <Button
                            v-if="
                                can.close &&
                                year.status === 'open' &&
                                year.periods.every((p) => p.status === 'closed')
                            "
                            variant="destructive"
                            class="h-11"
                            :disabled="processing"
                            @click="closeYear(year.id, year.name)"
                            >{{ t('accounting.close_year') }}</Button
                        >
                    </div>
                    <ul class="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        <li
                            v-for="period in year.periods"
                            :key="period.id"
                            class="flex flex-wrap items-center gap-2 rounded-md border p-2 text-sm"
                        >
                            <span class="min-w-20 flex-1 capitalize">{{
                                month.format(new Date(period.starts_on))
                            }}</span>
                            <span
                                class="rounded px-2 py-0.5 text-xs"
                                :class="badge[period.status]"
                                >{{
                                    t(
                                        `accounting.period_status.${period.status}`,
                                    )
                                }}</span
                            >
                            <template v-if="can.close">
                                <Button
                                    v-if="period.status === 'open'"
                                    variant="ghost"
                                    size="sm"
                                    class="h-9"
                                    @click="changePeriod(period.id, 'lock')"
                                    >{{ t('accounting.lock') }}</Button
                                >
                                <template
                                    v-else-if="period.status === 'locked'"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-9"
                                        @click="
                                            changePeriod(period.id, 'unlock')
                                        "
                                        >{{ t('accounting.unlock') }}</Button
                                    >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-9"
                                        @click="
                                            changePeriod(period.id, 'close')
                                        "
                                        >{{ t('accounting.close') }}</Button
                                    >
                                </template>
                            </template>
                        </li>
                    </ul>
                </article>
            </section>
        </template>
    </div>
</template>
