import { ref } from 'vue';

/**
 * État partagé de la visite guidée : n'importe quel bouton (carte, page du
 * guide) peut la lancer, le composant GuideTour l'affiche.
 */
const active = ref(false);
let autoStarted = false;

export function useGuideTour() {
    return {
        active,
        start: () => {
            active.value = true;
        },
        stop: () => {
            active.value = false;
        },
        /** Lancement automatique : une seule fois par chargement de l'application. */
        startOnce: (): boolean => {
            if (autoStarted) {
                return false;
            }

            autoStarted = true;
            active.value = true;

            return true;
        },
    };
}
