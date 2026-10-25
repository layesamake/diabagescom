<script setup lang="ts">
/**
 * Compteur − / + du mode simple : boutons de 48 px (moins en rouge clair,
 * plus en vert clair) et nombre de 26 à 30 px au milieu.
 *
 * Utilisé pour les quantités (Vendre, Stock, Achats) et le comptage des
 * billets de la caisse.
 */
import { Minus, Plus } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        valeur: number;
        min?: number;
        max?: number;
        /** Unité affichée sous le nombre (ex. « sacs »). */
        unite?: string;
    }>(),
    { min: 0, max: Number.MAX_SAFE_INTEGER },
);

const emit = defineEmits<{ 'update:valeur': [valeur: number] }>();

const peutRetirer = computed(() => props.valeur > props.min);
const peutAjouter = computed(() => props.valeur < props.max);

function retirer(): void {
    if (peutRetirer.value) {
        emit('update:valeur', props.valeur - 1);
    }
}

function ajouter(): void {
    if (peutAjouter.value) {
        emit('update:valeur', props.valeur + 1);
    }
}
</script>

<template>
    <div class="flex items-center gap-3">
        <button
            type="button"
            class="inline-flex size-12 shrink-0 items-center justify-center rounded-full disabled:opacity-40"
            :style="{
                backgroundColor: 'var(--s-rouge-clair)',
                color: 'var(--s-sur-rouge-clair)',
            }"
            :disabled="!peutRetirer"
            aria-label="Enlever un"
            @click="retirer"
        >
            <Minus :size="26" :stroke-width="3" />
        </button>

        <p class="min-w-14 text-center">
            <span
                class="block text-[30px] leading-none font-extrabold tabular-nums"
            >
                {{ valeur }}
            </span>
            <span
                v-if="unite"
                class="block text-[15px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                {{ unite }}
            </span>
        </p>

        <button
            type="button"
            class="inline-flex size-12 shrink-0 items-center justify-center rounded-full disabled:opacity-40"
            :style="{
                backgroundColor: 'var(--s-vert-clair)',
                color: 'var(--s-sur-vert-clair)',
            }"
            :disabled="!peutAjouter"
            aria-label="Ajouter un"
            @click="ajouter"
        >
            <Plus :size="26" :stroke-width="3" />
        </button>
    </div>
</template>
