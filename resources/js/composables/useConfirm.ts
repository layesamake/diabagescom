import { reactive } from 'vue';

/**
 * Fenêtres de confirmation de l'application (à la place de window.confirm /
 * window.prompt, techniques et peu lisibles) : titre clair, explication,
 * boutons nommés selon l'action. Une seule fenêtre à la fois, affichée par
 * <ConfirmDialog /> dans la mise en page.
 */
export type ConfirmOptions = {
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** « danger » : action irréversible ou destructive (bouton rouge). */
    tone?: 'primary' | 'danger';
    /** Demande un texte (motif…) ; la confirmation exige une saisie. */
    input?: { label: string; placeholder?: string; required?: boolean };
};

type State = ConfirmOptions & {
    open: boolean;
    value: string;
    resolve: ((value: string | null) => void) | null;
};

export const confirmState = reactive<State>({
    open: false,
    title: '',
    value: '',
    resolve: null,
});

function ask(options: ConfirmOptions): Promise<string | null> {
    // Une fenêtre déjà ouverte est annulée avant d'en ouvrir une autre.
    confirmState.resolve?.(null);

    return new Promise((resolve) => {
        Object.assign(confirmState, {
            message: undefined,
            confirmLabel: undefined,
            cancelLabel: undefined,
            tone: 'primary',
            input: undefined,
            ...options,
            open: true,
            value: '',
            resolve,
        });
    });
}

export function settleConfirm(value: string | null): void {
    const resolve = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;
    resolve?.(value);
}

/** Demande une confirmation ; renvoie true si l'utilisateur accepte. */
export async function confirmAction(options: ConfirmOptions): Promise<boolean> {
    return (await ask(options)) !== null;
}

/** Demande un texte (motif…) ; renvoie null si l'utilisateur annule. */
export async function askText(
    options: ConfirmOptions & { input: NonNullable<ConfirmOptions['input']> },
): Promise<string | null> {
    const value = await ask(options);

    return value === null ? null : value.trim();
}
