const montants = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/**
 * Affiche un montant stocké en entier (ex. 12500 → « 12 500 F »).
 * Unité « F » et non « XOF » : règle 4 du cahier des charges. Espace pour les
 * milliers, pas de décimale. Le formatage n'a lieu qu'à l'affichage ; les
 * calculs restent en entiers.
 */
export function formatXof(amount: number): string {
    return `${montants.format(amount)} F`;
}

/**
 * Montant du mode simple : « 45 000 F », jamais « XOF », jamais de décimale
 * (règle 4 du cahier des charges du mode simple). Même rendu que formatXof,
 * nom conservé pour la lisibilité des écrans du mode simple.
 */
export function formatFranc(amount: number): string {
    return formatXof(amount);
}

/** Montant saisi (« 12 500 », « 12500 ») → entier XOF ; 0 si vide ou invalide. */
export function parseAmount(value: string | number | null | undefined): number {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value > 0 ? value : 0;
    }

    const digits = (value ?? '').replace(/[\s  ]/g, '');

    return /^\d{1,15}$/.test(digits) ? Number(digits) : 0;
}

/**
 * Quantité saisie (« 12 », « 1,5 ») → millièmes (BigInt), ou null si invalide.
 * Même règle que le serveur : au plus 3 décimales, jamais de flottant.
 */
export function parseQuantity(value: string): bigint | null {
    const normalized = value.replace(/[\s  ]/g, '').replace(',', '.');
    const match = /^(\d{1,12})(?:\.(\d{1,3}))?$/.exec(normalized);

    if (!match) {
        return null;
    }

    return BigInt(match[1]) * 1000n + BigInt((match[2] ?? '').padEnd(3, '0'));
}

/** Quantité (millièmes) × prix unitaire, arrondi au franc (demi vers le haut). */
export function lineAmount(quantity: string, unitPrice: number): number {
    const milli = parseQuantity(quantity);

    if (milli === null) {
        return 0;
    }

    return Number((milli * BigInt(unitPrice) + 500n) / 1000n);
}

/** Affichage d'une quantité en millièmes : 1500n → « 1,5 ». */
export function formatQuantity(milli: bigint): string {
    const whole = milli / 1000n;
    const fraction = (milli % 1000n)
        .toString()
        .padStart(3, '0')
        .replace(/0+$/, '');

    return fraction ? `${whole},${fraction}` : `${whole}`;
}
