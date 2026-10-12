import { ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Comportement commun des listes avec recherche (articles, clients,
 * fournisseurs) : liste ouverte au clic, élément mis en évidence, choix au
 * clavier (↑ ↓ Entrée Échap) ou au doigt.
 */
export function useCombobox<T>(items: Ref<T[]>, onChoose: (item: T) => void) {
    const open = ref(false);
    const active = ref(-1);

    function move(step: number): void {
        if (!open.value) {
            open.value = true;
        }

        const count = items.value.length;

        if (count === 0) {
            active.value = -1;

            return;
        }

        active.value = (active.value + step + count) % count;
        document
            .querySelector(`[data-combobox-active="true"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }

    /** Choisit l'élément mis en évidence ; renvoie false s'il n'y en a pas. */
    function chooseActive(): boolean {
        const item = items.value[active.value];

        if (!open.value || item === undefined) {
            return false;
        }

        onChoose(item);

        return true;
    }

    function close(): void {
        open.value = false;
        active.value = -1;
    }

    return { open, active, move, chooseActive, close };
}

/**
 * Découpe un libellé pour mettre en gras la partie qui correspond à la
 * recherche (insensible à la casse et aux accents).
 */
export function highlight(
    label: string,
    query: string,
): { text: string; match: boolean }[] {
    const needle = normalize(query.trim());

    if (needle.length < 2) {
        return [{ text: label, match: false }];
    }

    const at = normalize(label).indexOf(needle);

    if (at < 0) {
        return [{ text: label, match: false }];
    }

    return [
        { text: label.slice(0, at), match: false },
        { text: label.slice(at, at + needle.length), match: true },
        { text: label.slice(at + needle.length), match: false },
    ].filter((part) => part.text !== '');
}

function normalize(value: string): string {
    // Même longueur que l'original : un caractère accentué devient une lettre.
    return value.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
}
