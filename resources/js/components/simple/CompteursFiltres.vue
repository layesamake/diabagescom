<script setup lang="ts">
/**
 * Trois cases de couleur avec un nombre : un appui filtre la liste en
 * dessous. Utilisé par le Stock (assez / bientôt fini / presque fini) et par
 * Mes ventes (espèces / téléphone / crédit).
 */
import type { CouleurSimple } from './palette';
import { fondClair, fondPlein } from './palette';

export type CaseFiltre = {
    cle: string;
    libelle: string;
    /** Nombre ou montant déjà formaté. */
    valeur: string;
    couleur: CouleurSimple;
};

defineProps<{
    cases: CaseFiltre[];
    /** Case active ; null = aucun filtre, tout est affiché. */
    actif: string | null;
}>();

const emit = defineEmits<{ filtrer: [cle: string | null] }>();
</script>

<template>
    <div class="grid grid-cols-3 gap-2">
        <button
            v-for="item in cases"
            :key="item.cle"
            type="button"
            class="flex min-h-20 flex-col items-center justify-center gap-1 rounded-[20px] px-2 py-3"
            :style="
                actif === item.cle
                    ? fondPlein[item.couleur]
                    : fondClair[item.couleur]
            "
            :aria-pressed="actif === item.cle"
            @click="emit('filtrer', actif === item.cle ? null : item.cle)"
        >
            <span class="text-[26px] leading-none font-extrabold tabular-nums">
                {{ item.valeur }}
            </span>
            <span class="text-center text-[14px] leading-tight font-semibold">
                {{ item.libelle }}
            </span>
        </button>
    </div>
</template>
