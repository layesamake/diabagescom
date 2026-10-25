/**
 * Résultat de la fermeture de caisse (mode simple, maquette 06d) : juste,
 * manque ou trop, avec le compté et l'attendu. Les écarts sont toujours dits
 * en francs entiers, jamais en « XOF ».
 */
export type ClosingFigures = {
    expected: number;
    counted: number;
    difference: number;
};

export type ClosingResult = {
    /** « reussi » : fond vert (c'est juste) ; « probleme » : fond rouge. */
    etat: 'reussi' | 'probleme';
    pictogramme: 'coche' | 'alerte';
    titre: string;
    detail: string;
};

function franc(amount: number): string {
    return `${Math.abs(Math.trunc(amount))
        .toLocaleString('fr-FR')
        .replace(/\s/g, ' ')} F`;
}

export function closingResult(figures: ClosingFigures): ClosingResult {
    const detail = `Compté : ${franc(figures.counted)} · Attendu : ${franc(figures.expected)}`;

    if (figures.difference === 0) {
        return {
            etat: 'reussi',
            pictogramme: 'coche',
            titre: 'C’est juste',
            detail,
        };
    }

    return {
        etat: 'probleme',
        pictogramme: 'alerte',
        titre:
            figures.difference < 0
                ? `Il manque ${franc(figures.difference)}`
                : `Il y a ${franc(figures.difference)} en trop`,
        detail,
    };
}
