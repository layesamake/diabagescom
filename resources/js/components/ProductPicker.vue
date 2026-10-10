<script setup lang="ts">
import { ScanBarcode } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { lookup, search } from '@/routes/products';

export type PickedProduct = {
    id: string;
    sku: string;
    name: string;
    unit: string;
    decimals: boolean;
    standard_cost: number | null;
};

/**
 * Sélection d'un article par recherche (nom, référence) ou par scan :
 * un lecteur de codes-barres « tape » le code puis Entrée → sélection directe.
 */
const props = defineProps<{ inputId: string; stockedOnly?: boolean }>();
const emit = defineEmits<{ select: [product: PickedProduct] }>();

const query = ref('');
const results = ref<PickedProduct[]>([]);
const open = ref(false);
const notFound = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function fetchJson<T>(url: string): Promise<T | null> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    return response.ok ? ((await response.json()) as T) : null;
}

async function runSearch(): Promise<void> {
    const q = query.value.trim();

    if (q.length < 2) {
        results.value = [];
        open.value = false;

        return;
    }

    const data = await fetchJson<{ products: PickedProduct[] }>(
        search({ query: { q, stocked: props.stockedOnly ? 1 : 0 } }).url,
    );
    results.value = data?.products ?? [];
    notFound.value = results.value.length === 0;
    open.value = true;
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
});

function choose(product: PickedProduct): void {
    emit('select', product);
    query.value = '';
    results.value = [];
    open.value = false;
}

// Entrée : code-barres exact d'abord (scan), sinon premier résultat.
async function onEnter(): Promise<void> {
    const code = query.value.trim();

    if (code === '') {
        return;
    }

    clearTimeout(timer);
    const exact = await fetchJson<{
        product: PickedProduct & { unit_decimals: boolean };
    }>(lookup({ query: { code } }).url);

    if (exact) {
        choose({
            ...exact.product,
            decimals: exact.product.unit_decimals,
            standard_cost: null,
        });

        return;
    }

    await runSearch();

    if (results.value.length === 1) {
        choose(results.value[0]);
    }
}
</script>

<template>
    <div class="relative">
        <ScanBarcode
            class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            :id="inputId"
            v-model="query"
            type="search"
            autocomplete="off"
            enterkeyhint="search"
            class="h-11 pl-10"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="`${inputId}-list`"
            :placeholder="t('stock.picker')"
            @keydown.enter.prevent="onEnter"
            @keydown.escape="open = false"
        />
        <ul
            v-if="open"
            :id="`${inputId}-list`"
            role="listbox"
            class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover shadow-md"
        >
            <li v-if="notFound" class="p-3 text-sm text-muted-foreground">
                {{ t('stock.picker_none') }}
            </li>
            <li
                v-for="product in results"
                :key="product.id"
                role="option"
                :aria-selected="false"
            >
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 px-3 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    @click="choose(product)"
                >
                    <span class="min-w-0 flex-1 truncate">{{
                        product.name
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >{{ product.sku }} · {{ product.unit }}</span
                    >
                </button>
            </li>
        </ul>
    </div>
</template>
