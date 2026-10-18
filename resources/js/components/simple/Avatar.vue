<script setup lang="ts">
/**
 * Photo ronde d'un client ou d'un article ; à défaut, ses initiales sur un
 * fond de couleur stable (même nom = même couleur, toujours).
 *
 * Les articles des entreprises existantes n'ont pas encore de photo : cette
 * pastille est ce qu'elles voient en attendant.
 */
import { computed } from 'vue';
import { fondClair, initiales, teinteDeNom } from './palette';

const props = withDefaults(
    defineProps<{
        nom: string;
        photoUrl?: string | null;
        taille?: number;
        /** Carré arrondi pour les articles, rond pour les personnes. */
        forme?: 'rond' | 'carre';
    }>(),
    { photoUrl: null, taille: 56, forme: 'rond' },
);

const style = computed(() => fondClair[teinteDeNom(props.nom)]);
const rayon = computed(() =>
    props.forme === 'rond' ? '9999px' : 'var(--s-rayon-champ)',
);
</script>

<template>
    <img
        v-if="photoUrl"
        :src="photoUrl"
        :alt="nom"
        class="shrink-0 object-cover"
        :style="{
            width: `${taille}px`,
            height: `${taille}px`,
            borderRadius: rayon,
        }"
        loading="lazy"
    />
    <span
        v-else
        class="inline-flex shrink-0 items-center justify-center font-extrabold"
        :style="[
            style,
            {
                width: `${taille}px`,
                height: `${taille}px`,
                borderRadius: rayon,
                fontSize: `${Math.round(taille * 0.38)}px`,
            },
        ]"
        :aria-label="nom"
    >
        {{ initiales(nom) }}
    </span>
</template>
