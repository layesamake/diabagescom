import { ref, watch } from 'vue';

export type TicketWidth = 58 | 80;

const KEY = 'diaba.ticket_width';

function stored(): TicketWidth {
    try {
        return localStorage.getItem(KEY) === '58' ? 58 : 80;
    } catch {
        return 80;
    }
}

// Largeur du papier de l'imprimante thermique : propre à chaque appareil (caisse).
const width = ref<TicketWidth>(typeof window === 'undefined' ? 80 : stored());

watch(width, (value) => {
    try {
        localStorage.setItem(KEY, String(value));
    } catch {
        // Stockage indisponible (navigation privée) : on garde la valeur en mémoire.
    }
});

export function useTicketWidth() {
    return { width };
}
