import { ref } from 'vue';

/**
 * État partagé de la recherche globale (Ctrl+K) : le bouton de l'en-tête, le
 * raccourci clavier et la boîte de dialogue s'y retrouvent.
 */
const opened = ref(false);

export function useCommandPalette() {
    return {
        opened,
        show: () => {
            opened.value = true;
        },
        hide: () => {
            opened.value = false;
        },
        toggle: () => {
            opened.value = !opened.value;
        },
    };
}
