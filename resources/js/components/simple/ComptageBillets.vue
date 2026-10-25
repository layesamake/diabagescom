<script setup lang="ts">
/**
 * Comptage de l'argent du tiroir, billet par billet (maquette 06b) : un appui
 * sur + ajoute un billet, sur − en retire un. Sert à l'ouverture et à la
 * fermeture de la caisse. Le total est calculé par le parent, et recalculé
 * par le serveur.
 */
import { Minus, Plus } from '@lucide/vue';
import {
    bump,
    DENOMINATIONS,
    subtotal,
    type CashCount,
    type Denomination,
} from '@/lib/cashCount';
import { formatFranc } from '@/lib/money';

const compte = defineModel<CashCount>({ required: true });

// Couleur propre à chaque valeur, comme sur les vrais billets (maquette 06b) ;
// texte blanc : contraste d'au moins 4,5:1 sur chacun de ces fonds.
const COULEURS: Record<Denomination, string> = {
    10000: '#2E5BA6',
    5000: '#2E7D5B',
    2000: '#7B3FA0',
    1000: '#B85C2C',
    500: '#587A1F',
    100: '#6B7280',
};

function libelle(valeur: Denomination): string {
    return valeur === 100
        ? 'pièces 100'
        : valeur.toLocaleString('fr-FR').replace(/\s/g, ' ');
}

function changer(valeur: Denomination, delta: number): void {
    compte.value = bump(compte.value, valeur, delta);
}
</script>

<template>
    <ul class="grid gap-3" aria-label="Billets et pièces">
        <li
            v-for="valeur in DENOMINATIONS"
            :key="valeur"
            class="flex items-center gap-3 rounded-[20px] p-2"
            :style="{ backgroundColor: 'var(--s-surface)' }"
        >
            <span
                class="inline-flex min-h-12 min-w-[104px] items-center justify-center rounded-[14px] px-3 text-center text-[17px] leading-tight font-extrabold tabular-nums"
                :style="{ backgroundColor: COULEURS[valeur], color: '#FFFFFF' }"
                >{{ libelle(valeur) }}</span
            >
            <button
                type="button"
                class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
                :style="{
                    backgroundColor: 'var(--s-rouge-clair)',
                    color: 'var(--s-sur-rouge-clair)',
                }"
                :aria-label="`Retirer un billet de ${libelle(valeur)}`"
                :disabled="compte[valeur] === 0"
                @click="changer(valeur, -1)"
            >
                <Minus :size="26" :stroke-width="3" />
            </button>
            <span
                class="min-w-8 text-center text-[26px] font-extrabold tabular-nums"
                aria-live="polite"
                >{{ compte[valeur] }}</span
            >
            <button
                type="button"
                class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
                :style="{
                    backgroundColor: 'var(--s-vert-clair)',
                    color: 'var(--s-sur-vert-clair)',
                }"
                :aria-label="`Ajouter un billet de ${libelle(valeur)}`"
                @click="changer(valeur, 1)"
            >
                <Plus :size="26" :stroke-width="3" />
            </button>
            <span
                v-if="compte[valeur] > 0"
                class="ml-auto pr-2 text-[17px] font-semibold tabular-nums"
                :style="{ color: 'var(--s-texte-doux)' }"
                >{{ formatFranc(subtotal(compte, valeur)) }}</span
            >
        </li>
    </ul>
</template>
