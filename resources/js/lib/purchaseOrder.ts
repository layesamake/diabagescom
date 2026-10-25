/**
 * Commande d'achat du mode simple : lignes (article, quantité, prix d'achat)
 * et total hors taxe affiché avant de commander. Le total définitif, taxes
 * comprises, est calculé par le serveur.
 */
export type OrderLine = {
    productId: string;
    /** Pièces entières, au moins 1. */
    quantity: number;
    /** Prix d'achat d'une pièce, en francs entiers. */
    unitCost: number;
};

export function orderTotal(lines: OrderLine[]): number {
    return lines.reduce(
        (sum, line) =>
            sum + Math.max(0, line.quantity) * Math.max(0, line.unitCost),
        0,
    );
}
