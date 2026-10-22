<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /**
     * Motifs proposés dans la feuille « Pourquoi ? » de la maquette 07b.
     * L'ordre est celui de la maquette.
     */
    public const array WITHDRAWAL_REASONS = ['abîmé', 'mort', 'perdu'];

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

        $maxQuantity = $products->reduce(
            fn (float $carry, array $product): float => max($carry, (float) $product['quantity']),
            0.0,
        );

        $products = $products
            ->map(function (array $product) use ($maxQuantity): array {
                // Ratio 0..1 pour la barre de progression visuelle (07a).
                // La barre reste visible en gardant un minimum de 5 %.
                $ratio = $maxQuantity > 0
                    ? (float) $product['quantity'] / $maxQuantity
                    : 0.0;
                $product['progress'] = $maxQuantity > 0
                    ? max(0.05, min(1.0, round($ratio, 3)))
                    : 0.0;
                $product['progress_max'] = $maxQuantity;

                return $product;
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
            // Maquette 07b : les motifs reconnus pour le retrait.
            'withdrawal_reasons' => self::WITHDRAWAL_REASONS,
            // Code de la phrase audio « Vous êtes sur Stock » (lot 4).
            'audio_code' => 'STK-01',
        ]);
    }

    /**
     * Boutons + / − d'une ligne de stock (maquette 07a). Une sortie
     * nécessite un motif reconnu ; un ajout utilise un motif générique.
     */
    public function adjust(
        Request $request,
        AdjustmentService $service,
        TenantContext $context,
    ): RedirectResponse {
        Gate::authorize('inventory.adjust');

        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'delta' => ['required', 'string', Rule::in(['+1', '-1'])],
            'reason' => ['nullable', 'string', 'max:120'],
        ], attributes: [
            'product_id' => 'article',
            'delta' => 'sens',
            'reason' => 'motif',
        ]);

        $isRemoval = $data['delta'] === '-1';
        $reason = $data['reason'] ?? ($isRemoval ? null : 'approvisionnement');

        if ($isRemoval && ! in_array($reason, self::WITHDRAWAL_REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => 'Choisissez un motif (abîmé, mort, perdu).',
            ]);
        }

        $warehouseId = $context->warehouse()?->id;
        abort_if($warehouseId === null, 422, 'Aucun dépôt actif.');

        $service->adjust(
            (string) $warehouseId,
            [[
                'product_id' => (string) $data['product_id'],
                'quantity' => $isRemoval ? '-1.000' : '1.000',
                'unit_cost' => null,
            ]],
            (string) $reason,
            $request->user(),
            (string) Str::uuid(),
        );

        return to_route('simple.stock', [
            'warehouse' => $warehouseId,
        ]);
    }
}
