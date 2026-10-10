const xof = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/**
 * Affiche un montant XOF stocké en entier (ex. 12500 → « 12 500 XOF »).
 * Le formatage n'a lieu qu'à l'affichage ; les calculs restent en entiers.
 */
export function formatXof(amount: number): string {
    return `${xof.format(amount)} XOF`;
}
