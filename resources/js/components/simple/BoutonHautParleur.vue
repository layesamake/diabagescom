<script setup lang="ts">
/**
 * Haut-parleur du mode simple.
 *
 * Règle centrale du système audio : l'icône ne s'affiche que si la phrase est
 * enregistrée dans la langue de l'entreprise. Pas d'enregistrement, pas
 * d'icône, jamais de voix de synthèse.
 *
 * Le lot 4 branchera la lecture réelle ; ici le composant expose déjà le code
 * de phrase et l'état « disponible », pour que les écrans soient corrects dès
 * maintenant.
 */
import { Volume2 } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Code de la phrase (COM-01, VEN-05…). */
        code: string;
        /** Vrai seulement si tous les morceaux audio existent. */
        disponible?: boolean;
        taille?: 'normal' | 'grand';
    }>(),
    { disponible: false, taille: 'normal' },
);

const emit = defineEmits<{ jouer: [code: string] }>();

const cote = computed(() => (props.taille === 'grand' ? 56 : 44));
</script>

<template>
    <button
        v-if="disponible"
        type="button"
        class="inline-flex shrink-0 items-center justify-center rounded-full"
        :style="{
            width: `${cote}px`,
            height: `${cote}px`,
            backgroundColor: 'var(--s-marque-2)',
            color: 'var(--s-sur-marque)',
        }"
        :aria-label="`Écouter (${code})`"
        @click="emit('jouer', code)"
    >
        <Volume2 :size="taille === 'grand' ? 28 : 24" :stroke-width="2" />
    </button>
</template>
