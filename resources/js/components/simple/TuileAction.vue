<script setup lang="ts">
/**
 * Grosse tuile d'action de l'accueil : 140 px de haut, rond clair + icône
 * 40 px, un mot (deux au plus). Une action = une couleur + un dessin + un mot.
 */
import type { Component } from 'vue';
import { computed } from 'vue';
import type { CouleurSimple } from './palette';
import { fondPlein } from './palette';

const props = defineProps<{
    libelle: string;
    icone: Component;
    couleur: CouleurSimple;
}>();

const emit = defineEmits<{ appui: [] }>();

const style = computed(() => fondPlein[props.couleur]);
</script>

<template>
    <button
        type="button"
        class="flex h-[140px] w-full flex-col items-center justify-center gap-3 rounded-[24px] px-3"
        :style="style"
        @click="emit('appui')"
    >
        <span
            class="inline-flex size-16 items-center justify-center rounded-full"
            style="background-color: rgb(255 255 255 / 0.28)"
        >
            <component
                :is="icone"
                :size="40"
                :stroke-width="2"
                aria-hidden="true"
            />
        </span>
        <span class="text-[22px] leading-tight font-extrabold">{{
            libelle
        }}</span>
    </button>
</template>
