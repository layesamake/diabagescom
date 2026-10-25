/**
 * Comptage de l'argent du tiroir par billet (mode simple, maquette 06b) :
 * billets de 10 000, 5 000, 2 000, 1 000 et 500 F, pièces de 100 F.
 * Même liste que `App\Domain\Cash\CashCount` : le serveur recalcule le total.
 */
export const DENOMINATIONS = [10000, 5000, 2000, 1000, 500, 100] as const;

export type Denomination = (typeof DENOMINATIONS)[number];
export type CashCount = Record<Denomination, number>;

export const MAX_PER_DENOMINATION = 100000;

export function emptyCount(): CashCount {
    return Object.fromEntries(
        DENOMINATIONS.map((value) => [value, 0]),
    ) as CashCount;
}

/** Ajoute ou retire des billets, sans jamais passer sous 0 ni au-dessus du plafond. */
export function bump(
    count: CashCount,
    value: Denomination,
    delta: number,
): CashCount {
    return {
        ...count,
        [value]: Math.min(
            MAX_PER_DENOMINATION,
            Math.max(0, count[value] + delta),
        ),
    };
}

export function subtotal(count: CashCount, value: Denomination): number {
    return value * count[value];
}

export function total(count: CashCount): number {
    return DENOMINATIONS.reduce(
        (sum, value) => sum + subtotal(count, value),
        0,
    );
}

export function isEmpty(count: CashCount): boolean {
    return DENOMINATIONS.every((value) => count[value] === 0);
}
