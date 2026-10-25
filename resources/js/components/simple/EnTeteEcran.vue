<script setup lang="ts">
/**
 * Barre d'en-tête des écrans du mode simple : retour (48 px), icône + titre,
 * haut-parleur. Fond vert foncé de la marque.
 *
 * L'icône de nuage barré apparaît dès que l'appareil est hors connexion : le
 * vendeur voit qu'il peut continuer, tout sera envoyé plus tard.
 */
import { ArrowLeft, CloudOff, Lock } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import BoutonHautParleur from './BoutonHautParleur.vue';

withDefaults(
    defineProps<{
        titre: string;
        icone?: Component;
        /**
         * Précision sous le titre, précédée d'un petit cadenas quand
         * `sousTitreVerrouille` est vrai (« Administrateur » sur l'écran
         * Messages audio, maquette 05a).
         */
        sousTitre?: string;
        sousTitreVerrouille?: boolean;
        /** Numéro de l'étape en cours (1..N) — affichée en pastille orange à droite. */
        etape?: number;
        /** Nombre total d'étapes ; la pastille n'apparaît que si les deux sont fournis. */
        etapeTotal?: number;
        /** Code de la phrase lue par le haut-parleur de l'écran. */
        codeAudio?: string;
        audioDisponible?: boolean;
        horsLigne?: boolean;
        retourVisible?: boolean;
    }>(),
    {
        audioDisponible: false,
        horsLigne: false,
        retourVisible: true,
        sousTitre: undefined,
        sousTitreVerrouille: false,
        etape: undefined,
        etapeTotal: undefined,
    },
);

const navigateurHorsLigne = ref(
    typeof navigator !== 'undefined' && !navigator.onLine,
);

function mettreAJourReseau(): void {
    navigateurHorsLigne.value =
        typeof navigator !== 'undefined' && !navigator.onLine;
}

onMounted(() => {
    mettreAJourReseau();
    window.addEventListener('online', mettreAJourReseau);
    window.addEventListener('offline', mettreAJourReseau);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', mettreAJourReseau);
    window.removeEventListener('offline', mettreAJourReseau);
});

const emit = defineEmits<{ retour: []; jouer: [code: string] }>();
const afficherPastille = (
    etape: number | undefined,
    etapeTotal: number | undefined,
): boolean => typeof etape === 'number' && typeof etapeTotal === 'number';
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

        <div class="min-w-0 flex-1">
            <h1 class="truncate text-[26px] leading-tight font-extrabold">
                {{ titre }}
            </h1>
            <p
                v-if="sousTitre"
                class="flex items-center gap-1 text-[13px] font-semibold opacity-90"
            >
                <Lock
                    v-if="sousTitreVerrouille"
                    :size="13"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                {{ sousTitre }}
            </p>
        </div>

        <span
            v-if="afficherPastille(etape, etapeTotal)"
            class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-sm font-extrabold tabular-nums"
            :style="{
                backgroundColor: 'var(--s-orange)',
                color: 'var(--s-sur-orange)',
            }"
            aria-live="polite"
            >{{ etape }} / {{ etapeTotal }}</span
        >

        <CloudOff
            v-if="horsLigne || navigateurHorsLigne"
            :size="26"
            :stroke-width="2"
            class="shrink-0"
            aria-label="Pas d'internet : tout sera envoyé plus tard"
        />

        <BoutonHautParleur
            v-if="codeAudio && !afficherPastille(etape, etapeTotal)"
            :code="codeAudio"
            :disponible="audioDisponible"
            @jouer="emit('jouer', $event)"
        />
    </header>
</template>
