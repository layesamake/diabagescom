<script setup lang="ts">
/**
 * Ligne d'alerte de l'accueil : rond de couleur + icône, gros nombre, texte
 * court, flèche. Rouge pour le stock presque fini, orange pour le crédit.
 */
import { ChevronRight } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { CouleurSimple } from './palette';
import { fondClair } from './palette';

const props = defineProps<{
    icone: Component;
    couleur: CouleurSimple;
    /** Nombre ou montant déjà formaté (« 3 », « 45 000 F »). */
    valeur: string;
    texte: string;
}>();

const emit = defineEmits<{ appui: [] }>();

const rond = computed(() => fondClair[props.couleur]);
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
        <span
            class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
            :style="rond"
        >
            <component
                :is="icone"
                :size="26"
                :stroke-width="2"
                aria-hidden="true"
            />
        </span>

        <span class="text-[26px] leading-none font-extrabold tabular-nums">
            {{ valeur }}
        </span>

        <span class="min-w-0 flex-1 text-[17px] font-semibold">{{
            texte
        }}</span>

        <ChevronRight
            :size="26"
            :stroke-width="2"
            class="shrink-0"
            :style="{ color: 'var(--s-texte-doux)' }"
            aria-hidden="true"
        />
    </button>
</template>
