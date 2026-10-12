import type { Directive } from 'vue';

/**
 * v-amount : affiche un montant XOF avec séparateurs de milliers pendant la
 * saisie (« 12500 » devient « 12 500 »), curseur conservé. La valeur reste
 * lue par parseAmount(), qui ignore les espaces. Réservé aux champs liés par
 * v-model et convertis côté client (jamais à un champ envoyé tel quel).
 */
const group = (digits: string): string =>
    digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

function reformat(el: HTMLInputElement): void {
    const caret = el.selectionStart ?? el.value.length;
    const digitsBeforeCaret = el.value
        .slice(0, caret)
        .replace(/\D/g, '').length;
    const digits = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const formatted = group(digits);

    if (formatted === el.value) {
        return;
    }

    el.value = formatted;

    // Replace le curseur après le même nombre de chiffres.
    let position = 0;

    for (
        let seen = 0;
        position < formatted.length && seen < digitsBeforeCaret;
        position++
    ) {
        if (/\d/.test(formatted[position])) {
            seen++;
        }
    }

    if (document.activeElement === el) {
        el.setSelectionRange(position, position);
    }

    // Prévient v-model de la nouvelle valeur affichée.
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

export const vAmount: Directive<HTMLInputElement> = {
    mounted(el) {
        el.setAttribute('inputmode', 'numeric');
        el.setAttribute('autocomplete', 'off');
        el.addEventListener('input', () => reformat(el));

        if (el.value !== '') {
            reformat(el);
        }
    },
    updated(el) {
        // Valeur remise par le code (réinitialisation, montant proposé…).
        if (document.activeElement !== el && el.value !== '') {
            reformat(el);
        }
    },
};
