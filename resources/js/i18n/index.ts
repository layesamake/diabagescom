import { fr } from './fr';

/**
 * Traductions de l'interface.
 *
 * Chaque langue fournit un dictionnaire de même forme que `fr` (le wolof viendra
 * s'ajouter ici). Les clés sont typées : une clé inexistante est une erreur de
 * compilation. Paramètres : `t('onboarding.created', { name: 'Ma Boutique' })`.
 */
type Messages = typeof fr;

type Leaves<T, Prefix extends string = ''> = {
    [K in keyof T & string]: T[K] extends string
        ? `${Prefix}${K}`
        : Leaves<T[K], `${Prefix}${K}.`>;
}[keyof T & string];

export type TranslationKey = Leaves<Messages>;

const dictionaries: Record<string, Messages> = { fr };

let current: Messages = fr;

export function setLocale(locale: string): void {
    current = dictionaries[locale] ?? fr;
}

/** Libellé lisible d'une action d'audit (les codes contiennent des points). */
export function auditActionLabel(action: string): string {
    return (current.audit.actions as Record<string, string>)[action] ?? action;
}

export function t(
    key: TranslationKey,
    params: Record<string, string | number> = {},
): string {
    const value = key
        .split('.')
        .reduce<unknown>(
            (node, part) =>
                node !== null && typeof node === 'object'
                    ? (node as Record<string, unknown>)[part]
                    : undefined,
            current,
        );

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/\{(\w+)\}/g, (match, name: string) =>
        name in params ? String(params[name]) : match,
    );
}
