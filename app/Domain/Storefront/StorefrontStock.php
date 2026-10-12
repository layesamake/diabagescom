<?php

namespace App\Domain\Storefront;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Disponibilité des articles pour la boutique publique : stock total des dépôts
 * actifs, déduction faite du réservé. Un service n'a pas de limite de stock.
 * Contrôle indicatif : le stock ne bouge qu'à la validation du document de vente.
 */
final class StorefrontStock
{
    /**
     * @param  list<Product>  $products
     * @return array<string, float|null> id article => quantité disponible (null = illimité)
     */
    public function availability(string $tenantId, array $products): array
    {
        $result = [];
        $stockable = [];

        foreach ($products as $product) {
            if ($product->type === 'stockable') {
                $stockable[] = $product->id;
                $result[$product->id] = 0.0;
            } else {
                $result[$product->id] = null;
            }
        }

        if ($stockable === []) {
            return $result;
        }

        $rows = DB::table('inventory_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.tenant_id', $tenantId)
            ->whereNull('w.archived_at')
            ->whereIn('b.product_id', $stockable)
            ->groupBy('b.product_id')
            ->selectRaw('b.product_id, SUM(b.quantity_on_hand - b.quantity_reserved) AS available')
            ->get();

        foreach ($rows as $row) {
            $result[(string) $row->product_id] = max(0.0, (float) $row->available);
        }

        return $result;
    }
}
