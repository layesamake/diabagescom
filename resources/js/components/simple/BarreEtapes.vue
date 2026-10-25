<script setup lang="ts">
/**
 * Barre d'étapes des Premiers pas : segments verts pour les étapes faites,
 * orange pour celle en cours, gris pour celles à faire.
 *
 * Affichée sur l'accueil (bande « Premiers pas »), dans le guide et dans
 * l'écran des messages audio.
 */
import { computed } from 'vue';

type EtatSegment = 'faite' | 'passee' | 'en-cours' | 'a-faire';

const props = withDefaults(
    defineProps<{
        total: number;
        faites: number;
        /** Vrai si une étape est commencée mais pas finie. */
        enCours?: boolean;
        /** États ordonnés, utilisés quand la progression n'est pas séquentielle. */
        etats?: EtatSegment[];
        /** Nom annoncé par les lecteurs d'écran. */
        ariaLabel?: string;
        libelle?: string | null;
    }>(),
    { enCours: false, ariaLabel: 'Progression', libelle: null },
);

const segments = computed<EtatSegment[]>(
    () =>
        props.etats ??
        Array.from({ length: props.total }, (_, index): EtatSegment => {
            if (index < props.faites) {
                return 'faite';
            }

            return index === props.faites && props.enCours
                ? 'en-cours'
                : 'a-faire';
        }),
);
const totalAffiche = computed(() => props.etats?.length ?? props.total);
const traitees = computed(() =>
    props.etats
        ? props.etats.filter((etat) => etat === 'faite' || etat === 'passee')
              .length
        : props.faites,
);

const couleurs = {
    faite: 'var(--s-vert-fonce)',
    passee: 'var(--s-vert-clair)',
    'en-cours': 'var(--s-orange)',
    'a-faire': 'var(--s-bordure)',
} as const;
</script>

<template>
    <div class="grid gap-2">
        <div
            v-if="libelle"
            class="flex items-baseline justify-between gap-2 text-[15px] font-semibold"
        >
            <span>{{ libelle }}</span>
            <span class="tabular-nums"
                >{{ traitees }} / {{ totalAffiche }}</span
            >
        </div>

        <div
            class="flex gap-1"
            role="progressbar"
            :aria-label="ariaLabel"
            :aria-valuenow="traitees"
            :aria-valuemin="0"
            :aria-valuemax="totalAffiche"
        >
            <span
                v-for="(etat, index) in segments"
                :key="index"
                class="h-3 flex-1 rounded-full"
                :style="{ backgroundColor: couleurs[etat] }"
            />
        </div>
    </div>
</template>
