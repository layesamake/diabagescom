<script setup lang="ts">
/**
 * Barre d'en-tête des écrans du mode simple : retour (48 px), icône + titre,
 * haut-parleur. Fond vert foncé de la marque.
 *
 * L'icône de nuage barré apparaît dès que l'appareil est hors connexion : le
 * vendeur voit qu'il peut continuer, tout sera envoyé plus tard.
 */
import { ArrowLeft, CloudOff } from '@lucide/vue';
import type { Component } from 'vue';
import BoutonHautParleur from './BoutonHautParleur.vue';

withDefaults(
    defineProps<{
        titre: string;
        icone?: Component;
        /** Code de la phrase lue par le haut-parleur de l'écran. */
        codeAudio?: string;
        audioDisponible?: boolean;
        horsLigne?: boolean;
        retourVisible?: boolean;
    }>(),
    { audioDisponible: false, horsLigne: false, retourVisible: true },
);

const emit = defineEmits<{ retour: []; jouer: [code: string] }>();
</script>

<template>
    <header
        class="flex items-center gap-3 px-4 py-3"
        :style="{
            backgroundColor: 'var(--s-marque)',
            color: 'var(--s-sur-marque)',
        }"
    >
        <button
            v-if="retourVisible"
            type="button"
            class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
            :style="{ backgroundColor: 'var(--s-marque-2)' }"
            aria-label="Revenir en arrière"
            @click="emit('retour')"
        >
            <ArrowLeft :size="26" :stroke-width="2" />
        </button>

        <component
            :is="icone"
            v-if="icone"
            :size="26"
            :stroke-width="2"
            class="shrink-0"
            aria-hidden="true"
        />

        <h1
            class="min-w-0 flex-1 truncate text-[26px] leading-tight font-extrabold"
        >
            {{ titre }}
        </h1>

        <CloudOff
            v-if="horsLigne"
            :size="26"
            :stroke-width="2"
            class="shrink-0"
            aria-label="Pas d'internet : tout sera envoyé plus tard"
        />

        <BoutonHautParleur
            v-if="codeAudio"
            :code="codeAudio"
            :disponible="audioDisponible"
            @jouer="emit('jouer', $event)"
        />
    </header>
</template>
