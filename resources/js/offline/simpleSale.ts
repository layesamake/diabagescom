import type { SalePayload } from '@/offline/sync';

/**
 * Commande de vente du mode simple (docs/08) : même forme que celle du POS
 * expert, donc revalidée par le même serveur à la reconnexion.
 *
 * Le prix de chaque ligne est celui affiché à l'écran au moment de la vente
 * (« prix capturé ») : si le prix a changé depuis, le serveur place la vente
 * en revue au lieu de l'accepter silencieusement.
 */
export type SimpleSaleInput = {
    warehouseId: string;
    cashSessionId: string;
    customerId: string | null;
    lines: { productId: string; quantity: number; unitPrice: number }[];
    /** Moyen de paiement, ou « credit » : aucune somme encaissée. */
    method: 'cash' | 'wave' | 'orange_money' | 'credit';
};

export function buildSimpleSalePayload(input: SimpleSaleInput): SalePayload {
    const total = input.lines.reduce(
        (sum, line) => sum + line.unitPrice * line.quantity,
        0,
    );

    return {
        warehouse_id: input.warehouseId,
        cash_session_id: input.cashSessionId,
        customer_id: input.customerId,
        negative_stock_reason: null,
        lines: input.lines.map((line) => ({
            product_id: line.productId,
            quantity: String(line.quantity),
            unit_price: line.unitPrice,
            discount: 0,
        })),
        tenders:
            input.method === 'credit'
                ? []
                : [{ method: input.method, amount: total, reference: null }],
    };
}
