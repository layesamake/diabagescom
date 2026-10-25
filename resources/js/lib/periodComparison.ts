/**
 * Comparaison des résultats avec la période précédente (maquette 11) :
 * « Mieux que septembre », « Moins bien que la semaine dernière ».
 */
export type PeriodKey = 'week' | 'month' | 'year';

export type ComparisonMessage = {
    sens: 'mieux' | 'moins' | 'pareil';
    texte: string;
};

/** Nom de la période précédente : le mois (« septembre »), « la semaine dernière » ou l'année. */
export function previousLabel(key: PeriodKey, previousFrom: string): string {
    if (key === 'week') {
        return 'la semaine dernière';
    }

    if (key === 'year') {
        return previousFrom.slice(0, 4);
    }

    const [annee, mois] = previousFrom.split('-').map(Number);

    return new Intl.DateTimeFormat('fr-FR', {
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(annee, mois - 1, 1)));
}

/** « que » devant une consonne, « qu’ » devant une voyelle (« qu’octobre », « qu’avril »). */
export function que(cible: string): string {
    return /^[aeiouyàâéèêîïôûh]/i.test(cible) ? `qu’${cible}` : `que ${cible}`;
}

/**
 * Message de comparaison, ou null quand on ne peut pas comparer (aucune vente
 * sur la période précédente, ou chiffre non visible pour cet utilisateur).
 */
export function comparisonMessage(
    key: PeriodKey,
    previousFrom: string,
    change: number | null,
): ComparisonMessage | null {
    if (change === null) {
        return null;
    }

    const cible = que(previousLabel(key, previousFrom));

    if (change > 0) {
        return { sens: 'mieux', texte: `Mieux ${cible}` };
    }

    return change < 0
        ? { sens: 'moins', texte: `Moins bien ${cible}` }
        : { sens: 'pareil', texte: `Pareil ${cible}` };
}
