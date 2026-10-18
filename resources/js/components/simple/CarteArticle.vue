<script setup lang="ts">
/**
 * Carte d'article de l'écran Vendre : photo, nom, prix, pastille de quantité.
 * Un appui ajoute 1 au panier (règle : un geste = une action).
 */
import { formatFranc } from '@/lib/money';
import Avatar from './Avatar.vue';

const props = withDefaults(
    defineProps<{
        nom: string;
        prix: number;
        photoUrl?: string | null;
        /** Quantité déjà au panier : pastille verte si supérieure à zéro. */
        quantite?: number;
    }>(),
    { photoUrl: null, quantite: 0 },
);

const emit = defineEmits<{ ajouter: [] }>();
</script>

<template>
    <button
        type="button"
        class="relative flex w-full flex-col items-center gap-2 rounded-[20px] p-3 text-center"
        :style="{
            backgroundColor: 'var(--s-surface)',
            border: '1px solid var(--s-bordure)',
            color: 'var(--s-texte)',
        }"
        @click="emit('ajouter')"
    >
        <Avatar :nom="nom" :photo-url="photoUrl" :taille="72" forme="carre" />

        <span
            class="line-clamp-2 min-h-11 text-[15px] leading-tight font-semibold"
        >
            {{ nom }}
        </span>
        <span class="text-[17px] font-extrabold tabular-nums">
            {{ formatFranc(prix) }}
        </span>

        <span
            v-if="props.quantite > 0"
            class="absolute top-2 right-2 inline-flex min-w-8 items-center justify-center rounded-full px-2 py-1 text-[17px] font-extrabold tabular-nums"
            :style="{
                backgroundColor: 'var(--s-vert)',
                color: 'var(--s-sur-vert)',
            }"
            :aria-label="`${props.quantite} au panier`"
        >
            {{ props.quantite }}
        </span>
    </button>
</template>
