<script setup lang="ts">
/**
 * Retour plein écran après une action : vert si c'est réussi, rouge s'il y a
 * un problème. Gros pictogramme de 80 px, montant, une ou deux actions.
 *
 * La vibration accompagne le retour visuel (règle 5), si l'appareil la gère.
 */
import { Check, X } from '@lucide/vue';
import { onMounted } from 'vue';
import { formatFranc } from '@/lib/money';
import BoutonHautParleur from './BoutonHautParleur.vue';

const props = withDefaults(
    defineProps<{
        /** « reussi » : fond vert ; « probleme » : fond rouge. */
        etat: 'reussi' | 'probleme';
        titre: string;
        montant?: number | null;
        /** Phrase courte sous le montant. */
        detail?: string | null;
        actionPrincipale?: string | null;
        actionSecondaire?: string | null;
        codeAudio?: string;
        audioDisponible?: boolean;
    }>(),
    {
        montant: null,
        detail: null,
        actionPrincipale: null,
        actionSecondaire: null,
        audioDisponible: false,
    },
);

const emit = defineEmits<{
    principale: [];
    secondaire: [];
    jouer: [code: string];
}>();

onMounted(() => {
    navigator.vibrate?.(props.etat === 'reussi' ? 120 : [80, 60, 80]);
});
</script>

<template>
    <div
        class="flex min-h-dvh flex-col items-center justify-center gap-5 px-6 text-center"
        :style="
            etat === 'reussi'
                ? 'background-color: var(--s-vert-fonce); color: var(--s-sur-vert-fonce);'
                : 'background-color: var(--s-rouge); color: var(--s-sur-rouge);'
        "
        role="status"
    >
        <span
            class="inline-flex size-28 items-center justify-center rounded-full"
            style="background-color: rgb(255 255 255 / 0.22)"
        >
            <component
                :is="etat === 'reussi' ? Check : X"
                :size="80"
                :stroke-width="2.5"
                aria-hidden="true"
            />
        </span>

        <p class="text-[26px] leading-tight font-extrabold">{{ titre }}</p>

        <p
            v-if="montant !== null"
            class="text-[46px] leading-none font-extrabold tabular-nums"
        >
            {{ formatFranc(montant) }}
        </p>

        <p v-if="detail" class="text-[17px] font-semibold opacity-95">
            {{ detail }}
        </p>

        <BoutonHautParleur
            v-if="codeAudio"
            :code="codeAudio"
            :disponible="audioDisponible"
            taille="grand"
            @jouer="emit('jouer', $event)"
        />

        <div class="mt-2 grid w-full max-w-[360px] gap-3">
            <button
                v-if="actionPrincipale"
                type="button"
                class="h-16 w-full rounded-[24px] text-[20px] font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    color: 'var(--s-texte)',
                }"
                @click="emit('principale')"
            >
                {{ actionPrincipale }}
            </button>
            <button
                v-if="actionSecondaire"
                type="button"
                class="h-16 w-full rounded-[24px] text-[20px] font-extrabold"
                style="
                    background-color: rgb(255 255 255 / 0.22);
                    color: inherit;
                "
                @click="emit('secondaire')"
            >
                {{ actionSecondaire }}
            </button>
        </div>
    </div>
</template>
