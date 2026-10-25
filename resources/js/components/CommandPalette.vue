<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CornerDownLeft,
    FileText,
    Package,
    Search,
    Truck,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAppContext } from '@/composables/useAppContext';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useQuickLinks } from '@/composables/useQuickLinks';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { edit as productEdit } from '@/routes/products';
import { index as customersIndex } from '@/routes/customers';
import { index as searchIndex } from '@/routes/search';
import { show as saleShow } from '@/routes/sales';
import { index as suppliersIndex } from '@/routes/suppliers';

/**
 * Recherche globale (Ctrl+K, ⌘K) : retrouver une page, une action, un
 * article, un client, un fournisseur ou un document en tapant quelques
 * lettres. Flèches pour choisir, Entrée pour ouvrir, Échap pour fermer.
 */
type Group = 'pages' | 'products' | 'customers' | 'suppliers' | 'documents';
type Entry = {
    id: string;
    group: Group;
    title: string;
    detail?: string;
    icon: Component;
    href: string;
};
type Hit = {
    id: string;
    label: string | null;
    detail: string | null;
    type?: string;
};
type Remote = {
    products: Hit[];
    customers: Hit[];
    suppliers: Hit[];
    documents: Hit[];
};

const GROUPS: Group[] = [
    'pages',
    'products',
    'customers',
    'suppliers',
    'documents',
];

const palette = useCommandPalette();
const { context } = useAppContext();
const { links } = useQuickLinks();

const query = ref('');
const remote = ref<Remote | null>(null);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const fold = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

const pageEntries = computed<Entry[]>(() => {
    const tokens = fold(query.value).split(/\s+/).filter(Boolean);

    return links.value
        .filter((link) => {
            const haystack = fold(`${link.title} ${link.keywords}`);

            return tokens.every((token) => haystack.includes(token));
        })
        .slice(0, tokens.length === 0 ? 8 : 6)
        .map((link) => ({
            id: `page-${link.key}`,
            group: 'pages' as const,
            title: link.title,
            icon: link.icon,
            href: link.href,
        }));
});

function documentTitle(hit: Hit): string {
    const type = t(`sales.types.${hit.type ?? 'invoice'}` as TranslationKey);

    return hit.label
        ? `${type} ${hit.label}`
        : `${type} (${t('search.draft')})`;
}

const remoteEntries = computed<Entry[]>(() => {
    const data = remote.value;

    if (!data) {
        return [];
    }

    return [
        ...data.products.map((hit) => ({
            id: `product-${hit.id}`,
            group: 'products' as const,
            title: hit.label ?? '',
            detail: hit.detail ?? undefined,
            icon: Package,
            href: productEdit(hit.id).url,
        })),
        ...data.customers.map((hit) => ({
            id: `customer-${hit.id}`,
            group: 'customers' as const,
            title: hit.label ?? '',
            detail: hit.detail ?? undefined,
            icon: Users,
            href: customersIndex({ query: { q: hit.label ?? '' } }).url,
        })),
        ...data.suppliers.map((hit) => ({
            id: `supplier-${hit.id}`,
            group: 'suppliers' as const,
            title: hit.label ?? '',
            detail: hit.detail ?? undefined,
            icon: Truck,
            href: suppliersIndex({ query: { q: hit.label ?? '' } }).url,
        })),
        ...data.documents.map((hit) => ({
            id: `document-${hit.id}`,
            group: 'documents' as const,
            title: documentTitle(hit),
            detail: hit.detail ?? undefined,
            icon: FileText,
            href: saleShow(hit.id).url,
        })),
    ];
});

// Liste à plat dans l'ordre d'affichage : c'est elle que parcourent les flèches.
const entries = computed<Entry[]>(() =>
    GROUPS.flatMap((group) =>
        [...pageEntries.value, ...remoteEntries.value].filter(
            (entry) => entry.group === group,
        ),
    ),
);

const sections = computed(() =>
    GROUPS.map((group) => ({
        group,
        items: entries.value.filter((entry) => entry.group === group),
    })).filter((section) => section.items.length > 0),
);

const indexOf = (entry: Entry): number =>
    entries.value.findIndex((e) => e.id === entry.id);

async function runRemote(): Promise<void> {
    const q = query.value.trim();
    controller?.abort();

    if (q.length < 2 || !navigator.onLine) {
        remote.value = null;

        return;
    }

    controller = new AbortController();

    try {
        const response = await fetch(searchIndex({ query: { q } }).url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller.signal,
        });

        remote.value = response.ok ? ((await response.json()) as Remote) : null;
    } catch {
        // Requête annulée par une frappe plus récente, ou réseau coupé : on garde les pages.
    }
}

watch(query, () => {
    active.value = 0;
    clearTimeout(timer);
    timer = setTimeout(() => void runRemote(), 250);
});

watch(
    () => entries.value.length,
    (count) => {
        if (active.value >= count) {
            active.value = Math.max(0, count - 1);
        }
    },
);

watch(palette.opened, async (open) => {
    if (open) {
        query.value = '';
        remote.value = null;
        active.value = 0;
        await nextTick();
        input.value?.focus();
    } else {
        controller?.abort();
    }
});

function go(entry: Entry | undefined): void {
    if (!entry) {
        return;
    }

    palette.hide();
    router.visit(entry.href);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(entries.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(entries.value[active.value]);
    }
}

function onHotkey(event: KeyboardEvent): void {
    if (!context.value) {
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        palette.toggle();
    }
}

onMounted(() => window.addEventListener('keydown', onHotkey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onHotkey);
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <Dialog
        :open="palette.opened.value"
        @update:open="(open: boolean) => (palette.opened.value = open)"
    >
        <DialogContent
            class="top-[12%] translate-y-0 gap-0 p-0 sm:max-w-xl"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">{{ t('search.title') }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                t('search.hint')
            }}</DialogDescription>

            <div class="flex items-center gap-3 border-b px-4">
                <Search
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    ref="input"
                    v-model="query"
                    type="search"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-list"
                    :aria-activedescendant="
                        entries[active]
                            ? `palette-${entries[active].id}`
                            : undefined
                    "
                    :aria-label="t('search.title')"
                    :placeholder="t('search.placeholder')"
                    autocomplete="off"
                    class="h-14 w-full bg-transparent text-base outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
            </div>

            <div
                id="palette-list"
                role="listbox"
                class="max-h-[60vh] overflow-y-auto p-2"
            >
                <p
                    v-if="entries.length === 0"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('search.empty', { q: query }) }}
                </p>

                <template v-for="section in sections" :key="section.group">
                    <p
                        class="px-3 pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{
                            t(
                                `search.groups.${section.group}` as TranslationKey,
                            )
                        }}
                    </p>
                    <button
                        v-for="entry in section.items"
                        :id="`palette-${entry.id}`"
                        :key="entry.id"
                        type="button"
                        role="option"
                        :aria-selected="indexOf(entry) === active"
                        class="flex min-h-11 w-full items-center gap-3 rounded-md px-3 text-left text-sm"
                        :class="
                            indexOf(entry) === active
                                ? 'bg-accent text-accent-foreground'
                                : 'hover:bg-accent/60'
                        "
                        @mousemove="active = indexOf(entry)"
                        @click="go(entry)"
                    >
                        <component
                            :is="entry.icon"
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            entry.title
                        }}</span>
                        <span
                            v-if="entry.detail"
                            class="shrink-0 text-xs text-muted-foreground"
                            >{{ entry.detail }}</span
                        >
                        <CornerDownLeft
                            v-if="indexOf(entry) === active"
                            class="hidden size-4 shrink-0 text-muted-foreground md:block"
                            aria-hidden="true"
                        />
                    </button>
                </template>
            </div>

            <p
                class="hidden border-t px-4 py-2 text-xs text-muted-foreground md:block"
            >
                {{ t('search.footer') }}
            </p>
        </DialogContent>
    </Dialog>
</template>
