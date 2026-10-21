<?php

namespace App\Domain\Sales;

use App\Domain\Inventory\Quantity;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Sélectionne les articles d'ajout rapide du point de vente.
 *
 * Ce service est partagé par les interfaces experte et simple afin qu'elles
 * affichent le même catalogue, le même prix et le même stock disponible.
 */
final class FrequentProducts
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forWarehouse(string $warehouseId): array
    {
        $ranked = DB::table('sales_document_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.warehouse_id', $warehouseId)->where('d.type', 'invoice')->where('d.status', 'validated')
            ->where('d.validated_at', '>=', now()->subDays(60))
            ->groupBy('l.product_id')->orderByRaw('count(*) desc')->limit(12)->pluck('l.product_id')->all();

        $products = Product::query()->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereNull('archived_at')->where('active', true)
            ->when($ranked !== [], fn ($query) => $query->whereIn('id', $ranked), fn ($query) => $query->orderBy('name')->limit(12))
            ->get()
            ->sortBy(fn (Product $product) => $ranked === [] ? $product->name : array_search($product->id, $ranked, true))
            ->values();
        $available = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $products->pluck('id'))->pluck('quantity_on_hand', 'product_id');

        return array_values($products->map(fn (Product $product) => [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'type' => $product->type,
            'unit' => $product->unit->code,
            'decimals' => $product->unit->decimals_allowed,
            'standard_cost' => null,
            'sale_price' => $product->sale_price,
            'tax_rate_basis_points' => $product->taxRate->rate_basis_points,
            'available' => $product->type !== 'service' ? Quantity::of((string) ($available[$product->id] ?? '0'))->format() : null,
            'image_url' => CatalogImages::url('products.image', $product->image_path, ['product' => $product->id], $product->version),
        ])->all());
    }
}
