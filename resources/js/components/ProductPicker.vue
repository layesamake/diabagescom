<script setup lang="ts">
import { Camera, ChevronDown, ScanBarcode } from '@lucide/vue';
import { ref, watch } from 'vue';
import CameraScanner from '@/components/CameraScanner.vue';
import { highlight, useCombobox } from '@/composables/useCombobox';
import { cameraAvailable } from '@/lib/barcodeScanner';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { lookupOffline, searchProductsOffline } from '@/offline/sync';
import type { PickedProduct as CatalogPickedProduct } from '@/types/catalog';
import { lookup, search } from '@/routes/products';

export type PickedProduct = CatalogPickedProduct;

/**
 * Sélection d'un article : liste déroulante au clic, recherche dès deux
 * lettres (la première réponse est présélectionnée, Entrée la choisit),
 * flèches du clavier, ou scan : un lecteur de codes-barres « tape » le code
 * puis Entrée → sélection directe.
 */
const props = defineProps<{
    inputId: string;
    stockedOnly?: boolean;
    warehouseId?: string;
}>();
const emit = defineEmits<{ select: [product: PickedProduct] }>();

const query = ref('');
const results = ref<PickedProduct[]>([]);
const notFound = ref(false);
const { open, active, move, chooseActive, close } = useCombobox(
    results,
    (product) => choose(product),
);
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
    const typed = query.value.trim();
    // Moins de deux lettres : toute la liste (par ordre alphabétique) à parcourir.
    const q = typed.length < 2 ? '' : typed;

    // Hors connexion (ou réseau coupé) : catalogue local synchronisé.
    const data = navigator.onLine
        ? await fetchJson<{ products: PickedProduct[] }>(
              search({
                  query: {
                      q,
                      stocked: props.stockedOnly ? 1 : 0,
                      ...(props.warehouseId
                          ? { warehouse: props.warehouseId }
                          : {}),
                  },
              }).url,
          ).catch(() => null)
        : null;
    results.value =
        data?.products ?? (await searchProductsOffline(q, props.stockedOnly));
    notFound.value = results.value.length === 0;
    open.value = true;
    // Texte saisi (pas un code numérique) : la première réponse est présélectionnée.
    active.value =
        q !== '' && !/^\d+$/.test(q) && results.value.length > 0 ? 0 : -1;
}

function browse(): void {
    if (open.value) {
        close();

        return;
    }

    void runSearch();
}

function onBlur(): void {
    // Laisse le temps au clic sur un article d'être pris en compte.
    setTimeout(close, 150);
}

watch(query, (value) => {
    clearTimeout(timer);
    // Chaque frappe annule la présélection : un scan ne prend jamais un article survolé.
    active.value = -1;

    if (value.trim() === '') {
        close();

        return;
    }

    timer = setTimeout(runSearch, 250);
});

function choose(product: PickedProduct, clear = true): void {
    emit('select', product);

    if (clear) {
        query.value = '';
        results.value = [];
        close();
    }
}

/**
 * Article correspondant exactement à un code (code-barres, référence) :
 * serveur si en ligne, sinon catalogue de l'appareil.
 */
async function lookupCode(code: string): Promise<PickedProduct | null> {
    const online = navigator.onLine
        ? await fetchJson<{
              product: PickedProduct & { unit_decimals: boolean };
          }>(lookup({ query: { code } }).url).catch(() => undefined)
        : undefined;
    const offline = online === undefined ? await lookupOffline(code) : null;

    if (offline && !(props.stockedOnly && offline.type === 'service')) {
        return offline;
    }

    if (online && !(props.stockedOnly && online.product.type === 'service')) {
        return {
            ...online.product,
            decimals: online.product.unit_decimals,
            standard_cost: null,
            available: null,
        };
    }

    return null;
}

// Caméra : chaque code lu ajoute l'article ; la caméra reste ouverte.
const cameraOpen = ref(false);
const cameraFeedback = ref<{ ok: boolean; text: string } | null>(null);
const canUseCamera = cameraAvailable();

async function onCameraCode(code: string): Promise<void> {
    const product = await lookupCode(code);

    if (product) {
        choose(product, false);
        cameraFeedback.value = {
            ok: true,
            text: t('scanner.added', { name: product.name }),
        };
    } else {
        cameraFeedback.value = {
            ok: false,
            text: t('scanner.unknown', { code }),
        };
    }
}

function openCamera(): void {
    cameraFeedback.value = null;
    cameraOpen.value = true;
}

// Entrée : code-barres exact d'abord (scan), sinon premier résultat.
// Le champ est vidé dès l'appui sur Entrée : avec un réseau lent, un second scan
// rapide ne se colle pas au premier, et la réponse du premier n'efface pas la
// saisie du second.
async function onEnter(): Promise<void> {
    // Un article mis en évidence (flèches ou première réponse) : on le prend.
    if (chooseActive()) {
        return;
    }

    const code = query.value.trim();

    if (code === '') {
        return;
    }

    clearTimeout(timer);
    query.value = '';
    close();
    const product = await lookupCode(code);

    if (product) {
        choose(product, false);

        return;
    }

    // Pas un code exact : recherche texte, si rien d'autre n'a été saisi entre-temps.
    if (query.value !== '') {
        return;
    }

    query.value = code;
    clearTimeout(timer);
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
            :class="canUseCamera ? 'h-11 pr-22 pl-10' : 'h-11 pr-11 pl-10'"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="`${inputId}-list`"
            :aria-activedescendant="
                active >= 0 ? `${inputId}-opt-${active}` : undefined
            "
            :placeholder="t('stock.picker')"
            @click="open || runSearch()"
            @blur="onBlur"
            @keydown.down.prevent="open ? move(1) : runSearch()"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="onEnter"
            @keydown.escape="close()"
        />
        <button
            v-if="canUseCamera"
            type="button"
            class="absolute top-1/2 right-10 flex size-9 -translate-y-1/2 items-center justify-center rounded-md text-primary hover:bg-accent"
            :aria-label="t('scanner.open')"
            :title="t('scanner.open')"
            @click="openCamera"
        >
            <Camera class="size-5" />
        </button>
        <button
            type="button"
            tabindex="-1"
            class="absolute top-1/2 right-1 flex size-9 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
            :aria-label="t('combobox.browse')"
            @mousedown.prevent="browse"
        >
            <ChevronDown
                class="size-4 transition-transform"
                :class="{ 'rotate-180': open }"
            />
        </button>
        <ul
            v-if="open"
            :id="`${inputId}-list`"
            role="listbox"
            class="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover py-1 text-popover-foreground shadow-md"
        >
            <li v-if="notFound" class="p-3 text-sm text-muted-foreground">
                {{ t('stock.picker_none') }}
            </li>
            <li
                v-for="(product, index) in results"
                :id="`${inputId}-opt-${index}`"
                :key="product.id"
                role="option"
                :aria-selected="index === active"
                :data-combobox-active="index === active"
                class="flex min-h-11 cursor-pointer items-center gap-3 px-3 text-sm"
                :class="index === active ? 'bg-accent' : 'hover:bg-accent/60'"
                @mousedown.prevent="choose(product)"
                @mouseenter="active = index"
            >
                <span class="min-w-0 flex-1 truncate">
                    <template
                        v-for="(part, i) in highlight(product.name, query)"
                        :key="i"
                    >
                        <mark
                            v-if="part.match"
                            class="rounded-sm bg-primary/25 font-semibold text-inherit"
                            >{{ part.text }}</mark
                        >
                        <template v-else>{{ part.text }}</template>
                    </template>
                </span>
                <span class="text-xs text-muted-foreground"
                    >{{ product.sku }} · {{ product.unit
                    }}<template
                        v-if="
                            product.available !== null &&
                            product.available !== undefined
                        "
                    >
                        ·
                        {{
                            t('pos.available', {
                                quantity: product.available,
                            })
                        }}</template
                    ></span
                >
            </li>
        </ul>
        <CameraScanner
            v-model:open="cameraOpen"
            :feedback="cameraFeedback"
            @detected="onCameraCode"
        />
    </div>
</template>
