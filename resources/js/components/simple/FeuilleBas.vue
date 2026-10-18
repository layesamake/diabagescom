<script setup lang="ts">
/**
 * Feuille qui monte du bas : voile sombre + panneau arrondi, choix en gros
 * boutons, bouton Retour. Utilisée pour le paiement, le motif d'un retrait,
 * la fiche d'un client, le détail d'une vente et le choix de la langue.
 *
 * Fermeture par le voile, par le bouton Retour ou par la touche Échap ; le
 * défilement de la page est bloqué pendant l'ouverture.
 */
import { onBeforeUnmount, watch } from 'vue';
import BoutonHautParleur from './BoutonHautParleur.vue';

const props = withDefaults(
    defineProps<{
        ouverte: boolean;
        titre: string;
        codeAudio?: string;
        audioDisponible?: boolean;
    }>(),
    { audioDisponible: false },
);

const emit = defineEmits<{ fermer: []; jouer: [code: string] }>();

function surTouche(evenement: KeyboardEvent): void {
    if (evenement.key === 'Escape') {
        emit('fermer');
    }
}

watch(
    () => props.ouverte,
    (ouverte) => {
        document.body.style.overflow = ouverte ? 'hidden' : '';

        if (ouverte) {
            window.addEventListener('keydown', surTouche);
        } else {
            window.removeEventListener('keydown', surTouche);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', surTouche);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="ouverte"
            class="fixed inset-0 z-50 flex items-end justify-center"
            role="dialog"
            aria-modal="true"
        >
            <button
                type="button"
                class="absolute inset-0"
                style="background-color: rgb(4 32 26 / 0.55)"
                aria-label="Fermer"
                @click="emit('fermer')"
            />

            <div
                class="relative mx-auto w-full max-w-[430px] rounded-t-[28px] px-4 pt-4 pb-6"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    color: 'var(--s-texte)',
                }"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-[22px] leading-tight font-extrabold">
                        {{ titre }}
                    </h2>
                    <BoutonHautParleur
                        v-if="codeAudio"
                        :code="codeAudio"
                        :disponible="audioDisponible"
                        @jouer="emit('jouer', $event)"
                    />
                </div>

                <div class="grid gap-3">
                    <slot />
                </div>

                <button
                    type="button"
                    class="mt-4 h-16 w-full rounded-[24px] text-[20px] font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-fond)',
                        color: 'var(--s-texte)',
                        border: '1px solid var(--s-bordure)',
                    }"
                    @click="emit('fermer')"
                >
                    Retour
                </button>
            </div>
        </div>
    </Teleport>
</template>
