<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 5 : lecture tactile du stock du dépôt sélectionné.
 * Les entrées et sorties restent portées par les services Inventory existants.
 */
class SimpleStockController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(Request $request, StockAccess $access, TenantContext $context): Response
    {
        Gate::authorize('inventory.view');

        $warehouses = $access->accessible();
        $warehouseId = (string) $request->query('warehouse', $context->warehouse()->id ?? $warehouses->first()?->id);
        abort_unless($warehouses->contains('id', $warehouseId), 403);

        $products = Product::query()
            ->select('products.id', 'products.name', 'products.sku', 'products.unit_id')
            ->selectRaw('COALESCE(b.quantity_on_hand, 0) AS quantity_on_hand, s.reorder_point')
            ->leftJoin('inventory_balances as b', fn ($join) => $join->on('b.product_id', '=', 'products.id')->where('b.warehouse_id', '=', $warehouseId))
            ->leftJoin('product_warehouse_settings as s', fn ($join) => $join->on('s.product_id', '=', 'products.id')->where('s.warehouse_id', '=', $warehouseId))
            ->with('unit:id,code')
            ->whereIn('products.type', ['stockable', 'consumable'])
            ->whereNull('products.archived_at')
            ->orderBy('products.name')
            ->get()
            ->map(function (Product $product): array {
                $quantity = Quantity::of((string) $product->getAttribute('quantity_on_hand'));
                $reorder = $product->getAttribute('reorder_point');
                $low = $reorder !== null && $quantity->compare(Quantity::of((string) $reorder)) <= 0;
                $status = ! $quantity->isPositive() ? 'empty' : ($low ? 'low' : 'enough');

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unit->code,
                    'quantity' => $quantity->value,
                    'quantity_display' => $quantity->format(),
                    'status' => $status,
                ];
            })
            ->values();

        return Inertia::render('simple/Stock', [
            'warehouse' => $warehouses->firstWhere('id', $warehouseId)?->only(['id', 'code', 'name']),
            'products' => $products,
            'counters' => [
                'enough' => $products->where('status', 'enough')->count(),
                'low' => $products->where('status', 'low')->count(),
                'empty' => $products->where('status', 'empty')->count(),
            ],
        ]);
    }
}
