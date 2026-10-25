<script setup lang="ts">
/**
 * Ligne d'article du stock et des réceptions : photo 56 px, nom, jauge de
 * couleur (assez / bientôt fini / presque fini) et quantité restante.
 *
 * La couleur remplace le mot « seuil de stock » : vert, orange, rouge.
 */
import { computed } from 'vue';
import type { CouleurSimple } from './palette';
import { fondPlein, jaugeStock } from './palette';
import Avatar from './Avatar.vue';

const props = withDefaults(
    defineProps<{
        nom: string;
        /** Quantité restante, déjà formatée (« 12 », « 1,5 »). */
        quantite: string;
        niveau: keyof typeof jaugeStock;
        photoUrl?: string | null;
        unite?: string;
        /** Part de la jauge remplie, de 0 à 100. */
        remplissage?: number;
    }>(),
    { photoUrl: null, remplissage: 100 },
);

const emit = defineEmits<{ appui: [] }>();

const couleur = computed<CouleurSimple>(() => jaugeStock[props.niveau]);
const largeur = computed(
    () => `${Math.min(100, Math.max(6, props.remplissage))}%`,
);
</script>

<template>
    <button
        type="button"
        class="flex w-full items-center gap-3 px-4 py-3 text-left"
        :style="{
            backgroundColor: 'var(--s-surface)',
            color: 'var(--s-texte)',
        }"
        @click="emit('appui')"
    >
        <Avatar :nom="nom" :photo-url="photoUrl" :taille="56" forme="carre" />

        <span class="min-w-0 flex-1">
            <span class="block truncate text-[17px] font-semibold">{{
                nom
            }}</span>
            <span
                class="mt-2 block h-3 w-full overflow-hidden rounded-full"
                :style="{ backgroundColor: 'var(--s-bordure)' }"
            >
                <span
                    class="block h-full rounded-full"
                    :style="[fondPlein[couleur], { width: largeur }]"
                />
            </span>
        </span>

        <span class="shrink-0 text-right">
            <span
                class="block text-[26px] leading-none font-extrabold tabular-nums"
            >
                {{ quantite }}
            </span>
            <span
                v-if="unite"
                class="block text-[15px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                {{ unite }}
            </span>
        </span>
    </button>
</template>
