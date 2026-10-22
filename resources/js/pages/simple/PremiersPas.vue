<script setup lang="ts">
/**
 * Premiers pas du mode simple (maquette 03).
 *
 * Onze étapes : « Mon entreprise » faite (coche verte), « Enregistrer la voix »
 * en cours (cadre orange, badge Chef, bouton Commencer), puis six étapes à
 * venir visibles et un bouton « + 5 autres étapes » qui déplie la suite.
 * Le repère « n / N » vient du bandeau via les props etape / etapeTotal.
 */
import { Head } from '@inertiajs/vue3';
import {
    Boxes,
    Check,
    ChevronRight,
    CircleUserRound,
    Mic,
    Package,
    Plus,
    ShoppingCart,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BarreEtapes from '@/components/simple/BarreEtapes.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

type EtatEtape = 'faite' | 'en-cours' | 'a-venir';

interface Etape {
    numero: number;
    titre: string;
    icone: typeof ShoppingCart;
    etat: EtatEtape;
}

const afficherToutes = ref(false);

const etapes: Etape[] = [
    {
        numero: 1,
        titre: 'Mon entreprise',
        icone: CircleUserRound,
        etat: 'faite',
    },
    { numero: 2, titre: 'Enregistrer la voix', icone: Mic, etat: 'en-cours' },
    { numero: 3, titre: 'Dire les phrases', icone: Mic, etat: 'a-venir' },
    { numero: 4, titre: 'Compter mon stock', icone: Boxes, etat: 'a-venir' },
    { numero: 5, titre: 'Ouvrir la caisse', icone: Wallet, etat: 'a-venir' },
    {
        numero: 6,
        titre: 'Faire une vente',
        icone: ShoppingCart,
        etat: 'a-venir',
    },
    { numero: 7, titre: 'Recevoir un achat', icone: Package, etat: 'a-venir' },
    {
        numero: 8,
        titre: 'Ajouter un client',
        icone: CircleUserRound,
        etat: 'a-venir',
    },
    { numero: 9, titre: 'Voir mes résultats', icone: Package, etat: 'a-venir' },
    {
        numero: 10,
        titre: 'Enregistrer un message',
        icone: Mic,
        etat: 'a-venir',
    },
    {
        numero: 11,
        titre: 'Inviter un vendeur',
        icone: CircleUserRound,
        etat: 'a-venir',
    },
];

const visibles = computed(() =>
    afficherToutes.value ? etapes : etapes.slice(0, 6),
);
const restantes = computed(() =>
    Math.max(etapes.length - visibles.value.length, 0),
);
</script>

<template>
    <Head title="Premiers pas" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Premiers pas"
        :etape="2"
        :etape-total="11"
    />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BarreEtapes :total="11" :faites="1" :en-cours="true" />

        <ul class="flex flex-col gap-2">
            <li
                v-for="etape in visibles"
                :key="etape.numero"
                class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3"
                :style="
                    etape.etat === 'en-cours'
                        ? {
                              borderColor: 'var(--s-orange)',
                              backgroundColor: 'var(--s-surface)',
                          }
                        : undefined
                "
            >
                <span
                    v-if="etape.etat === 'faite'"
                    class="grid size-9 shrink-0 place-items-center rounded-full"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                    aria-hidden="true"
                >
                    <Check :size="18" :stroke-width="3" />
                </span>
                <span
                    v-else-if="etape.etat === 'en-cours'"
                    class="grid size-9 shrink-0 place-items-center rounded-full text-base font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    {{ etape.numero }}
                </span>
                <span
                    v-else
                    class="grid size-9 shrink-0 place-items-center rounded-full text-sm font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-bordure)',
                        color: 'var(--s-texte-doux)',
                    }"
                >
                    {{ etape.numero }}
                </span>

                <component
                    :is="etape.icone"
                    :size="22"
                    :stroke-width="2"
                    class="shrink-0"
                    :style="{
                        color:
                            etape.etat === 'a-venir'
                                ? 'var(--s-texte-doux)'
                                : 'var(--s-texte)',
                    }"
                    aria-hidden="true"
                />

                <span
                    class="min-w-0 flex-1 truncate text-base font-medium"
                    :class="{
                        'text-muted-foreground': etape.etat === 'a-venir',
                    }"
                >
                    {{ etape.titre }}
                </span>

                <span
                    v-if="etape.etat === 'en-cours'"
                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    Chef
                </span>
            </li>
        </ul>

        <div
            class="flex flex-col gap-4 rounded-2xl border p-4"
            :style="{
                borderColor: 'var(--s-orange)',
                backgroundColor: 'var(--s-surface)',
            }"
        >
            <div class="flex items-start gap-3">
                <span
                    class="grid size-12 shrink-0 place-items-center rounded-full"
                    :style="{
                        backgroundColor: 'var(--s-rouge)',
                        color: 'var(--s-sur-rouge)',
                    }"
                    aria-hidden="true"
                >
                    <Mic :size="22" :stroke-width="2" />
                </span>
                <div class="flex flex-col gap-1 text-sm">
                    <span>1. Choisir la langue</span>
                    <span>2. Dire les phrases</span>
                    <span class="text-muted-foreground">
                        71 phrases pour commencer · environ 30 min
                    </span>
                </div>
            </div>
            <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-base font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-vert)',
                    color: 'var(--s-sur-vert)',
                }"
            >
                Commencer
                <ChevronRight :size="20" :stroke-width="2.5" />
            </button>
        </div>

        <button
            v-if="!afficherToutes"
            type="button"
            class="inline-flex items-center justify-center gap-2 self-center rounded-full border border-dashed px-4 py-2 text-sm font-semibold"
            :style="{
                borderColor: 'var(--s-bordure)',
                color: 'var(--s-texte-doux)',
            }"
            @click="afficherToutes = true"
        >
            <Plus :size="16" :stroke-width="2.5" />
            + {{ restantes }} autres étapes
        </button>
    </div>
</template>
