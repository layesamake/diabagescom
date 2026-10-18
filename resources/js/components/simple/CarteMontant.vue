<script setup lang="ts">
/**
 * Carte vert foncé de l'accueil, de la caisse et des résultats : libellé,
 * montant en 46 px (graisse 800) et pastilles (nombre de ventes, tendance).
 *
 * Les montants s'écrivent toujours « 45 000 F » : espace pour les milliers,
 * pas de décimales, jamais « XOF ».
 */
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { formatFranc } from '@/lib/money';
import BoutonHautParleur from './BoutonHautParleur.vue';

withDefaults(
    defineProps<{
        libelle: string;
        montant: number;
        /** Pastilles libres (ex. « 12 ventes »). */
        pastilles?: string[];
        /** Tendance par rapport à la période précédente. */
        tendance?: 'hausse' | 'baisse' | null;
        codeAudio?: string;
        audioDisponible?: boolean;
    }>(),
    { pastilles: () => [], tendance: null, audioDisponible: false },
);

const emit = defineEmits<{ jouer: [code: string] }>();
</script>

<template>
    <section
        class="rounded-[24px] px-5 py-4"
        :style="{
            backgroundColor: 'var(--s-marque)',
            color: 'var(--s-sur-marque)',
        }"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-[17px] font-semibold opacity-90">{{ libelle }}</p>
            <BoutonHautParleur
                v-if="codeAudio"
                :code="codeAudio"
                :disponible="audioDisponible"
                @jouer="emit('jouer', $event)"
            />
        </div>

        <p class="mt-1 text-[46px] leading-none font-extrabold tabular-nums">
            {{ formatFranc(montant) }}
        </p>

        <div
            v-if="pastilles.length || tendance"
            class="mt-3 flex flex-wrap items-center gap-2"
        >
            <span
                v-for="pastille in pastilles"
                :key="pastille"
                class="inline-flex min-h-8 items-center rounded-full px-3 text-[15px] font-semibold"
                style="background-color: rgb(255 255 255 / 0.18)"
            >
                {{ pastille }}
            </span>
            <span
                v-if="tendance"
                class="inline-flex min-h-8 items-center gap-1 rounded-full px-3 text-[15px] font-semibold"
                :style="
                    tendance === 'hausse'
                        ? 'background-color: var(--s-vert); color: var(--s-sur-vert);'
                        : 'background-color: var(--s-rouge); color: var(--s-sur-rouge);'
                "
            >
                <component
                    :is="tendance === 'hausse' ? TrendingUp : TrendingDown"
                    :size="18"
                    :stroke-width="2.5"
                    aria-hidden="true"
                />
                {{ tendance === 'hausse' ? "Mieux qu'hier" : "Moins qu'hier" }}
            </span>
        </div>
    </section>
</template>
