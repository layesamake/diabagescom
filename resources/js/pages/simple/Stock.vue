<script setup lang="ts">
/**
 * Stock du mode simple (lot 5) — maquettes 07a (vue principale) et 07b
 * (feuille « Pourquoi ? » pour motif de retrait).
 *
 * L'écran est tactile : les gros boutons + / − appellent le service de stock
 * du dépôt actif. Une sortie (delta=-1) exige un motif (abîmé, mort, perdu).
 * Le compteur de progression sert à visualiser la quantité restante par
 * rapport au plus gros stock du dépôt.
 */
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    HelpCircle,
    Minus,
    Package,
    Plus,
    Skull,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { useAudio } from '@/composables/useAudio';
import { fondClair, fondPlein } from '@/components/simple/palette';

type Statut = 'enough' | 'low' | 'empty';

type Produit = {
    id: string;
    name: string;
    sku: string;
    unit: string;
    quantity: string;
    quantity_display: string;
    status: Statut;
    progress: number;
    progress_max: number;
};

const audio = useAudio();
const props = defineProps<{
    warehouse: { id: string; code: string; name: string };
    products: Produit[];
    counters: Record<Statut, number>;
    withdrawal_reasons: string[];
    audio_code: string;
}>();

const COULEURS: Record<Statut, 'vert' | 'orange' | 'rouge'> = {
    enough: 'vert',
    low: 'orange',
    empty: 'rouge',
};

const LIBELLES: Record<Statut, string> = {
    enough: 'Assez',
    low: 'Bientôt fini',
    empty: 'Presque fini',
};

const COUNTERS: Statut[] = ['enough', 'low', 'empty'];

// État de la feuille « Pourquoi ? » (07b).
const motifOuvert = ref<Produit | null>(null);
const motifEnVol = ref<string | null>(null);

const { can } = useAppContext();
const produitsVisibles = computed(() => props.products);

const motifIcones: Record<string, typeof Trash2> = {
    abîmé: Trash2,
    mort: Skull,
    perdu: HelpCircle,
};

const motifCouleurs: Record<string, 'rouge' | 'marque' | 'orange'> = {
    abîmé: 'rouge',
    mort: 'marque',
    perdu: 'orange',
};

function capitaliser(motif: string): string {
    // Le serveur stocke le motif normalisé (abîmé, mort, perdu) ; la
    // maquette 07b l'affiche avec la première lettre en capitale.
    return motif.charAt(0).toUpperCase() + motif.slice(1);
}

function ajuster(produit: Produit, signe: '+1' | '-1'): void {
    if (signe === '-1') {
        motifOuvert.value = produit;

        return;
    }

    router.post('/simple/stock/ajuster', {
        product_id: produit.id,
        delta: signe,
        reason: 'approvisionnement',
    });
}

function confirmerMotif(reason: string): void {
    if (motifOuvert.value === null) {
        return;
    }

    motifEnVol.value = reason;
    const produit = motifOuvert.value;
    router.post('/simple/stock/ajuster', {
        product_id: produit.id,
        delta: '-1',
        reason,
    });
    motifOuvert.value = null;
    motifEnVol.value = null;
}
</script>

<template>
    <Head title="Stock" />

    <EnTeteEcran
        titre="Stock"
        :icone="Package"
        :retour-visible="true"
        :code-audio="audio_code"
        :audio-disponible="audio.disponible(audio_code)"
        @jouer="audio.jouer"
    />

    <h1
        class="sr-only lg:not-sr-only lg:col-span-2 lg:text-[32px] lg:font-extrabold"
    >
        Stock
    </h1>

    <a
        v-if="can('catalog.create')"
        href="/simple/articles/nouveau"
        class="hidden min-h-12 items-center rounded-[18px] px-4 text-[17px] font-extrabold lg:inline-flex"
        :style="{
            backgroundColor: 'var(--s-bleu-clair)',
            color: 'var(--s-sur-bleu-clair)',
        }"
    >
        Ajouter un article
    </a>

    <div class="grid gap-4 p-4 pb-28">
        <!-- Trois compteurs colorés solides (07a) -->
        <div class="grid grid-cols-3 gap-2">
            <div
                v-for="cle in COUNTERS"
                :key="cle"
                class="flex min-h-20 flex-col items-center justify-center gap-1 rounded-[20px] px-2 py-3"
                :style="fondPlein[COULEURS[cle]]"
                role="status"
                :aria-label="`${counters[cle]} ${LIBELLES[cle].toLowerCase()}`"
            >
                <span
                    class="text-[26px] leading-none font-extrabold tabular-nums"
                >
                    {{ counters[cle] }}
                </span>
                <span
                    class="text-center text-[15px] leading-tight font-semibold"
                >
                    {{ LIBELLES[cle] }}
                </span>
            </div>
        </div>

        <!-- Liste des articles -->
        <section
            class="overflow-hidden rounded-[24px]"
            :style="{ border: '1px solid var(--s-bordure)' }"
        >
            <article
                v-for="produit in produitsVisibles"
                :key="produit.id"
                class="flex items-center gap-3 border-b px-4 py-3 last:border-b-0"
                :style="{ borderColor: 'var(--s-bordure)' }"
            >
                <!-- Photo placeholder colorée selon statut -->
                <span
                    class="grid size-14 shrink-0 place-items-center rounded-[16px] text-[15px] font-extrabold"
                    :style="fondClair[COULEURS[produit.status]]"
                    aria-hidden="true"
                >
                    photo
                </span>

                <div class="min-w-0 flex-1">
                    <strong class="block truncate text-[17px] font-semibold">
                        {{ produit.name }}
                    </strong>
                    <div
                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full"
                        :style="{ backgroundColor: 'var(--s-bordure)' }"
                        role="progressbar"
                        :aria-valuenow="Math.round(produit.progress * 100)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="`${produit.quantity_display} ${produit.unit}`"
                    >
                        <div
                            class="h-full rounded-full"
                            :style="{
                                width: `${Math.round(produit.progress * 100)}%`,
                                backgroundColor: `var(--s-${COULEURS[produit.status]})`,
                            }"
                        />
                    </div>
                </div>

                <span
                    class="shrink-0 text-right text-[19px] font-extrabold tabular-nums"
                >
                    {{ produit.quantity_display }}
                </span>

                <div class="flex shrink-0 flex-col gap-1">
                    <button
                        type="button"
                        class="inline-flex size-12 items-center justify-center rounded-[16px]"
                        :style="fondClair.vert"
                        aria-label="Ajouter au stock"
                        @click="ajuster(produit, '+1')"
                    >
                        <Plus :size="22" :stroke-width="2" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex size-12 items-center justify-center rounded-[16px]"
                        :style="fondClair.rouge"
                        aria-label="Retirer du stock"
                        @click="ajuster(produit, '-1')"
                    >
                        <Minus :size="22" :stroke-width="2" />
                    </button>
                </div>
            </article>

            <div
                v-if="produitsVisibles.length === 0"
                class="grid min-h-52 place-items-center p-6 text-center"
            >
                <div>
                    <Package
                        class="mx-auto mb-3 size-12"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        aria-hidden="true"
                    />
                    <p class="text-[17px] font-bold">Aucun article en stock</p>
                    <p
                        class="mt-1 text-[15px]"
                        :style="{ color: 'var(--s-texte-doux)' }"
                    >
                        Ajoutez un article puis son arrivage pour le voir ici.
                    </p>
                </div>
            </div>
        </section>
    </div>

    <!-- Maquette 07b : feuille « Pourquoi ? » pour motif de retrait -->
    <div
        v-if="motifOuvert"
        class="fixed inset-0 z-50 flex items-end"
        :style="{ backgroundColor: 'rgba(0,0,0,0.45)' }"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pourquoi-titre"
        @click.self="motifOuvert = null"
    >
        <div
            class="w-full rounded-t-[28px] p-5"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
            }"
        >
            <div class="flex items-start justify-between gap-3">
                <h2
                    id="pourquoi-titre"
                    class="text-[24px] leading-tight font-extrabold"
                >
                    Pourquoi ?
                </h2>
                <span
                    class="text-right text-[15px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    − 1 {{ motifOuvert.name }}
                </span>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                <button
                    v-for="motif in withdrawal_reasons"
                    :key="motif"
                    type="button"
                    class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-[20px] px-2 py-3 text-[16px] font-extrabold"
                    :style="fondClair[motifCouleurs[motif] ?? 'marque']"
                    :aria-pressed="motifEnVol === motif"
                    :disabled="motifEnVol !== null"
                    @click="confirmerMotif(motif)"
                >
                    <component
                        :is="motifIcones[motif] ?? AlertTriangle"
                        :size="28"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    {{ capitaliser(motif) }}
                </button>
            </div>

            <button
                type="button"
                class="mt-4 min-h-14 w-full rounded-[20px] px-4 text-[16px] font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    color: 'var(--s-texte)',
                    border: '1px solid var(--s-bordure)',
                }"
                @click="motifOuvert = null"
            >
                Retour
            </button>
        </div>
    </div>
</template>
