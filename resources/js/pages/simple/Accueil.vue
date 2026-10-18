<script setup lang="ts">
/**
 * Accueil provisoire du mode simple (lot 1).
 *
 * Les vrais contenus (carte « Aujourd'hui », tuiles, alertes, 7 derniers
 * jours) arrivent au lot 3 ; cet écran montre que la bascule, la mise en page
 * et la barre du bas fonctionnent, et donne le chemin vers la page de
 * comparaison des composants.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Settings2 } from '@lucide/vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { useAppContext } from '@/composables/useAppContext';
import { demo as simpleDemo, mode as simpleMode } from '@/routes/simple';

const { context } = useAppContext();

function versModeExpert(): void {
    router.post(simpleMode().url, { mode: 'expert' });
}
</script>

<template>
    <Head title="Mode simple" />

    <EnTeteEcran titre="Mode simple" :retour-visible="false" />

    <div class="grid gap-4 p-4">
        <p
            class="rounded-[24px] p-4 text-[17px] font-semibold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            Lot 1 livré : couleurs, police, 14 composants, bascule et barre du
            bas. Les écrans Vendre, Caisse et Accueil arrivent aux lots
            suivants.
        </p>

        <Link
            v-if="context?.role.code === 'admin'"
            :href="simpleDemo()"
            class="flex min-h-16 items-center justify-center gap-2 rounded-[24px] text-[20px] font-extrabold"
            :style="{
                backgroundColor: 'var(--s-vert)',
                color: 'var(--s-sur-vert)',
            }"
        >
            <Settings2 :size="26" :stroke-width="2" aria-hidden="true" />
            Voir les composants
        </Link>

        <button
            type="button"
            class="min-h-16 rounded-[24px] text-[20px] font-extrabold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
                border: '1px solid var(--s-bordure)',
            }"
            @click="versModeExpert"
        >
            Revenir au mode expert
        </button>
    </div>
</template>
