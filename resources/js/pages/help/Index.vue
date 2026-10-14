<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { index as backupIndex } from '@/routes/backup';
import { index as countsIndex } from '@/routes/counts';
import { index as helpIndex } from '@/routes/help';
import { index as membersIndex } from '@/routes/members';
import { create as posCreate } from '@/routes/pos';
import { create as productCreate } from '@/routes/products';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { create as salesCreate } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';

/**
 * Centre d'aide : comment faire les tâches courantes, questions fréquentes et
 * glossaire du métier. Une recherche filtre les trois rubriques ; les tâches
 * ne sont proposées qu'à qui peut les faire.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('help_center.page_title'), href: helpIndex() },
        ],
    },
});

const { can } = useAppContext();
const query = ref('');

const FAQ = [
    'invoice_fix',
    'offline',
    'search',
    'switch',
    'who_owes',
    'cash_close',
    'roles',
    'trial',
    'backup',
];
const GLOSSARY = [
    'credit_note',
    'quote',
    'draft',
    'receipt',
    'transfer',
    'stock_count',
    'low_stock',
    'warehouse',
    'cash_float',
    'cash_gap',
    'receivable',
    'purchase_request',
    'entry',
    'chart',
    'bank_rec',
    'vat_regime',
    'ninea',
];

const tasks = computed(() =>
    [
        {
            key: 'sell',
            href: posCreate().url,
            show: can('sale.create') && can('sale.validate'),
        },
        {
            key: 'quote',
            href: salesCreate('quote').url,
            show: can('sale.create'),
        },
        {
            key: 'product',
            href: productCreate().url,
            show: can('catalog.create'),
        },
        { key: 'stock', href: stockIndex().url, show: can('inventory.adjust') },
        {
            key: 'order',
            href: purchaseOrdersIndex().url,
            show: can('purchase.create'),
        },
        {
            key: 'count',
            href: countsIndex().url,
            show: can('inventory.count_create'),
        },
        { key: 'member', href: membersIndex().url, show: can('member.invite') },
        {
            key: 'backup',
            href: backupIndex().url,
            show: can('organization.manage'),
        },
    ]
        .filter((task) => task.show)
        .map((task) => ({
            key: task.key,
            href: task.href,
            title: t(`help_center.tasks.${task.key}.title` as TranslationKey),
            steps: t(
                `help_center.tasks.${task.key}.steps` as TranslationKey,
            ).split('\n'),
        })),
);

const faq = FAQ.map((key) => ({
    key,
    question: t(`help_center.faq.${key}.question` as TranslationKey),
    answer: t(`help_center.faq.${key}.answer` as TranslationKey),
}));

const glossary = GLOSSARY.map((key) => ({
    key,
    term: t(`help_center.glossary.${key}.term` as TranslationKey),
    definition: t(`help_center.glossary.${key}.definition` as TranslationKey),
}));

const fold = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

const tokens = computed(() => fold(query.value).split(/\s+/).filter(Boolean));
const matches = (...texts: string[]): boolean => {
    const haystack = fold(texts.join(' '));

    return tokens.value.every((token) => haystack.includes(token));
};

const shownTasks = computed(() =>
    tasks.value.filter((task) => matches(task.title, task.steps.join(' '))),
);
const shownFaq = computed(() =>
    faq.filter((item) => matches(item.question, item.answer)),
);
const shownGlossary = computed(() =>
    glossary.filter((item) => matches(item.term, item.definition)),
);
const nothing = computed(
    () =>
        shownTasks.value.length === 0 &&
        shownFaq.value.length === 0 &&
        shownGlossary.value.length === 0,
);
</script>

<template>
    <Head :title="t('help_center.page_title')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-8 p-4 md:p-8">
        <Heading
            :title="t('help_center.heading')"
            :description="t('help_center.description')"
        />

        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                v-model="query"
                type="search"
                class="h-12 pl-10"
                :aria-label="t('help_center.search_label')"
                :placeholder="t('help_center.search_placeholder')"
            />
        </div>

        <p
            v-if="nothing"
            class="text-center text-sm text-muted-foreground"
            role="status"
        >
            {{ t('help_center.empty', { q: query }) }}
        </p>

        <section
            v-if="shownTasks.length"
            class="space-y-3"
            aria-labelledby="help-tasks"
        >
            <h2 id="help-tasks" class="text-lg font-semibold tracking-tight">
                {{ t('help_center.sections.tasks') }}
            </h2>
            <ul class="space-y-2">
                <li v-for="task in shownTasks" :key="task.key">
                    <details
                        class="group rounded-lg border bg-card"
                        :open="tokens.length > 0"
                    >
                        <summary
                            class="flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 font-medium"
                        >
                            <ChevronRight
                                class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                                aria-hidden="true"
                            />
                            {{ task.title }}
                        </summary>
                        <div class="space-y-3 border-t px-4 py-3">
                            <ol class="list-decimal space-y-1.5 pl-5 text-sm">
                                <li
                                    v-for="(step, index) in task.steps"
                                    :key="index"
                                >
                                    {{ step }}
                                </li>
                            </ol>
                            <Button
                                as-child
                                size="sm"
                                class="min-h-11 md:min-h-9"
                            >
                                <Link :href="task.href">
                                    {{ t('help_center.open') }}
                                    <ChevronRight
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        </div>
                    </details>
                </li>
            </ul>
        </section>

        <section
            v-if="shownFaq.length"
            class="space-y-3"
            aria-labelledby="help-faq"
        >
            <h2 id="help-faq" class="text-lg font-semibold tracking-tight">
                {{ t('help_center.sections.faq') }}
            </h2>
            <ul class="space-y-2">
                <li v-for="item in shownFaq" :key="item.key">
                    <details
                        class="group rounded-lg border bg-card"
                        :open="tokens.length > 0"
                    >
                        <summary
                            class="flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 font-medium"
                        >
                            <ChevronRight
                                class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                                aria-hidden="true"
                            />
                            {{ item.question }}
                        </summary>
                        <p class="border-t px-4 py-3 text-sm leading-relaxed">
                            {{ item.answer }}
                        </p>
                    </details>
                </li>
            </ul>
        </section>

        <section
            v-if="shownGlossary.length"
            class="space-y-3"
            aria-labelledby="help-glossary"
        >
            <h2 id="help-glossary" class="text-lg font-semibold tracking-tight">
                {{ t('help_center.sections.glossary') }}
            </h2>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="item in shownGlossary"
                    :key="item.key"
                    class="rounded-lg border bg-card p-4"
                >
                    <dt class="font-semibold">{{ item.term }}</dt>
                    <dd class="mt-1 text-sm text-muted-foreground">
                        {{ item.definition }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
