<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Package,
    Plus,
    Printer,
    ScanBarcode,
    Settings2,
    Tags,
    Upload,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { settings as catalogSettings } from '@/routes/catalog';
import {
    create as productCreate,
    edit as productEdit,
    index as productsIndex,
    labels as labelsRoute,
} from '@/routes/products';
import { create as importCreate } from '@/routes/products/import';
import type { Option, Paginated, ProductRow } from '@/types';
import EmptyState from '@/components/EmptyState.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('catalog.title'), href: productsIndex() }],
    },
});

const props = defineProps<{
    products: Paginated<ProductRow>;
    filters: { q: string; category: string; status: string };
    categories: Option[];
    can: { create: boolean; import: boolean };
}>();

const q = ref(props.filters.q);
// Étiquettes : sélection d'articles (avec code-barres) puis impression dans un nouvel onglet.
const labelMode = ref(false);
const selected = ref<string[]>([]);
const labelCopies = ref(1);
const labelSize = ref('50x30');
const labelLayout = ref('sheet');
const labelPrice = ref(true);

function selectPage(): void {
    selected.value = props.products.data
        .filter((p) => p.barcode)
        .map((p) => p.id);
}

function openLabels(): void {
    const copies = Math.min(
        100,
        Math.max(1, Math.floor(labelCopies.value || 1)),
    );
    const url = labelsRoute({
        query: {
            ids: selected.value,
            copies,
            size: labelSize.value,
            layout: labelLayout.value,
            price: labelPrice.value ? 1 : 0,
            print: 1,
        },
    }).url;
    window.open(url, '_blank', 'noopener');
}
const category = ref(props.filters.category);
const status = ref(props.filters.status);

const selectClass =
    'flex h-11 rounded-md border border-input bg-transparent px-3 text-sm';

function search(): void {
    router.get(
        productsIndex().url,
        {
            q: q.value || undefined,
            category: category.value || undefined,
            status: status.value,
        },
        { preserveState: true, replace: true },
    );
}

// Recherche au fil de la frappe (temporisée) ; « Entrée » (lecteur de codes-barres) lance tout de suite.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 350);
});
watch([category, status], search);
</script>

<template>
    <Head :title="t('catalog.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('catalog.title')"
                :description="t('catalog.description')"
            />
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.create" as-child class="h-11">
                    <Link :href="productCreate()"
                        ><Plus class="size-4" />{{ t('catalog.add') }}</Link
                    >
                </Button>
                <Button
                    v-if="can.import"
                    as-child
                    variant="outline"
                    class="h-11"
                >
                    <Link :href="importCreate()"
                        ><Upload class="size-4" />{{
                            t('catalog.import')
                        }}</Link
                    >
                </Button>
                <Button
                    type="button"
                    :variant="labelMode ? 'default' : 'outline'"
                    class="h-11"
                    :aria-pressed="labelMode"
                    @click="labelMode = !labelMode"
                    ><Tags class="size-4" />{{
                        t('catalog.labels.button')
                    }}</Button
                >
                <Button as-child variant="ghost" class="h-11">
                    <Link :href="catalogSettings()"
                        ><Settings2 class="size-4" />{{
                            t('catalog.settings')
                        }}</Link
                    >
                </Button>
            </div>
        </div>

        <form
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_auto_auto]"
            role="search"
            @submit.prevent="search"
        >
            <!-- La recherche garde toute la largeur sauf sur grand écran. -->
            <div class="grid gap-1 sm:col-span-2 xl:col-span-1">
                <Label for="q" class="sr-only">{{ t('catalog.search') }}</Label>
                <div class="relative">
                    <ScanBarcode
                        class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="q"
                        v-model="q"
                        type="search"
                        autocomplete="off"
                        enterkeyhint="search"
                        class="h-11 pl-10"
                        :placeholder="t('catalog.search')"
                        aria-describedby="q-hint"
                        v-focus
                    />
                </div>
                <p id="q-hint" class="text-xs text-muted-foreground">
                    {{ t('catalog.search_hint') }}
                </p>
            </div>
            <div>
                <Label for="category" class="sr-only">{{
                    t('catalog.category')
                }}</Label>
                <select
                    id="category"
                    v-model="category"
                    :class="selectClass"
                    class="w-full"
                >
                    <option value="">{{ t('catalog.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </div>
            <div>
                <Label for="status" class="sr-only">{{
                    t('catalog.status_active')
                }}</Label>
                <select
                    id="status"
                    v-model="status"
                    :class="selectClass"
                    class="w-full"
                >
                    <option value="active">
                        {{ t('catalog.status_active') }}
                    </option>
                    <option value="archived">
                        {{ t('catalog.status_archived') }}
                    </option>
                    <option value="all">{{ t('catalog.status_all') }}</option>
                </select>
            </div>
        </form>

        <!-- Étiquettes : choisir des articles, puis imprimer une planche ou un rouleau. -->
        <section
            v-if="labelMode"
            class="space-y-3 rounded-lg border bg-muted/30 p-4"
            aria-labelledby="labels-title"
        >
            <h2 id="labels-title" class="font-semibold">
                {{ t('catalog.labels.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('catalog.labels.hint') }}
            </p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid gap-1">
                    <Label for="label-copies">{{
                        t('catalog.labels.copies')
                    }}</Label>
                    <Input
                        id="label-copies"
                        v-model.number="labelCopies"
                        type="number"
                        min="1"
                        max="100"
                        inputmode="numeric"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="label-size">{{
                        t('catalog.labels.size')
                    }}</Label>
                    <select
                        id="label-size"
                        v-model="labelSize"
                        :class="selectClass"
                    >
                        <option value="50x30">50 × 30 mm</option>
                        <option value="70x40">70 × 40 mm</option>
                        <option value="40x25">40 × 25 mm</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label for="label-layout">{{
                        t('catalog.labels.layout')
                    }}</Label>
                    <select
                        id="label-layout"
                        v-model="labelLayout"
                        :class="selectClass"
                    >
                        <option value="sheet">
                            {{ t('catalog.labels.sheet') }}
                        </option>
                        <option value="roll">
                            {{ t('catalog.labels.roll') }}
                        </option>
                    </select>
                </div>
                <label
                    class="flex min-h-11 items-center gap-2 self-end text-sm"
                >
                    <input
                        v-model="labelPrice"
                        type="checkbox"
                        class="size-5"
                    />
                    {{ t('catalog.labels.show_price') }}
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="h-11"
                    @click="selectPage"
                    >{{ t('catalog.labels.select_page') }}</Button
                >
                <Button
                    type="button"
                    class="h-11"
                    :disabled="selected.length === 0"
                    @click="openLabels"
                    ><Printer class="size-4" />{{
                        t('catalog.labels.open', { count: selected.length })
                    }}</Button
                >
            </div>
        </section>

        <EmptyState
            v-if="products.data.length === 0"
            :hint="filters.q ? undefined : t('empty.products')"
        >
            {{
                filters.q
                    ? t('catalog.empty_search', { q: filters.q })
                    : t('catalog.empty')
            }}
            <template v-if="!filters.q && (can.create || can.import)" #action>
                <div class="flex flex-wrap justify-center gap-2">
                    <Button v-if="can.create" as-child class="h-11">
                        <Link :href="productCreate()"
                            ><Plus class="size-4" />{{ t('catalog.add') }}</Link
                        >
                    </Button>
                    <Button
                        v-if="can.import"
                        as-child
                        variant="outline"
                        class="h-11"
                    >
                        <Link :href="importCreate()"
                            ><Upload class="size-4" />{{
                                t('catalog.import')
                            }}</Link
                        >
                    </Button>
                </div>
            </template>
        </EmptyState>

        <!-- Liste en cartes : lisible sur smartphone, sans défilement horizontal. -->
        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="product in products.data"
                :key="product.id"
                class="flex items-center"
            >
                <label
                    v-if="labelMode"
                    class="flex min-h-16 w-12 shrink-0 items-center justify-center"
                >
                    <input
                        v-model="selected"
                        type="checkbox"
                        :value="product.id"
                        :disabled="!product.barcode"
                        class="size-5"
                        :aria-label="
                            t('catalog.labels.select', { name: product.name })
                        "
                    />
                </label>
                <Link
                    :href="productEdit(product.id)"
                    class="flex min-h-16 min-w-0 flex-1 items-center gap-3 p-3 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                >
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        alt=""
                        class="size-12 shrink-0 rounded-md border object-cover"
                        loading="lazy"
                    />
                    <span
                        v-else
                        class="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted"
                    >
                        <Package
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="grid min-w-0 flex-1 gap-0.5">
                        <span class="truncate font-medium">{{
                            product.name
                        }}</span>
                        <span class="truncate text-sm text-muted-foreground">
                            {{ product.sku }}
                            <template v-if="product.barcode">
                                · {{ product.barcode }}</template
                            >
                            <template v-if="product.category">
                                · {{ product.category }}</template
                            >
                        </span>
                    </span>
                    <span
                        class="grid shrink-0 justify-items-end gap-0.5 text-right"
                    >
                        <span class="font-semibold tabular-nums">{{
                            formatXof(product.sale_price)
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            <template v-if="product.archived">{{
                                t('catalog.archived_badge')
                            }}</template>
                            <template v-else>/ {{ product.unit }}</template>
                        </span>
                    </span>
                </Link>
            </li>
        </ul>

        <nav
            v-if="products.prev_page_url || products.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="products.prev_page_url"
                :href="products.prev_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="products.next_page_url"
                :href="products.next_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
