<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockController extends Controller
{
    /**
     * Stock d'un dépôt : quantités, valorisation (si autorisé) et alertes de seuil.
     */
    public function index(Request $request, StockAccess $access, TenantContext $context): Response
    {
        Gate::authorize('inventory.view');

        $warehouses = $access->accessible();
        $warehouseId = (string) $request->query('warehouse', $context->warehouse()->id ?? $warehouses->first()?->id);
        abort_unless($warehouses->contains('id', $warehouseId), 403);

        $q = trim((string) $request->query('q', ''));
        $lowOnly = $request->boolean('low');
        $canSeeCosts = Gate::allows('report.margin_view');

        $rows = Product::query()
            ->select('products.id', 'products.sku', 'products.name', 'products.type', 'products.unit_id')
            ->selectRaw('COALESCE(b.quantity_on_hand, 0) AS quantity_on_hand, COALESCE(b.average_cost, 0) AS average_cost, s.reorder_point')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 'products.id')->where('b.warehouse_id', '=', $warehouseId))
            ->leftJoin('product_warehouse_settings as s', fn ($j) => $j->on('s.product_id', '=', 'products.id')->where('s.warehouse_id', '=', $warehouseId))
            ->with('unit:id,code,decimals_allowed')
            ->whereIn('products.type', ['stockable', 'consumable'])
            ->whereNull('products.archived_at')
            ->search($q)
            ->when($lowOnly, fn ($query) => $query->whereNotNull('s.reorder_point')->whereRaw('COALESCE(b.quantity_on_hand, 0) <= s.reorder_point'))
            ->orderBy('products.name')
            ->paginate(30)
            ->withQueryString()
            ->through(function (Product $p) use ($canSeeCosts) {
                $quantity = Quantity::of((string) $p->getAttribute('quantity_on_hand'));
                $reorder = $p->getAttribute('reorder_point');

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'unit' => $p->unit->code,
                    'decimals' => $p->unit->decimals_allowed,
                    'quantity' => $quantity->value,
                    'quantity_display' => $quantity->format(),
                    'reorder_point' => $reorder !== null ? Quantity::of((string) $reorder)->value : null,
                    'low' => $reorder !== null && $quantity->compare(Quantity::of((string) $reorder)) <= 0,
                    'negative' => $quantity->isNegative(),
                    'average_cost' => $canSeeCosts ? (int) $p->getAttribute('average_cost') : null,
                    'value' => $canSeeCosts && $quantity->isPositive() ? $quantity->times((int) $p->getAttribute('average_cost')) : null,
                ];
            });

        return Inertia::render('inventory/Stock', [
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
            'warehouseId' => $warehouseId,
            'rows' => $rows,
            'filters' => ['q' => $q, 'low' => $lowOnly],
            'lowCount' => $this->lowStockCount($warehouseId),
            'can' => [
                'adjust' => Gate::allows('inventory.adjust'),
                'costs' => $canSeeCosts,
            ],
        ]);
    }

    public function movements(Request $request, StockAccess $access): Response
    {
        Gate::authorize('inventory.view');

        $warehouseIds = $access->accessibleIds();
        $warehouse = (string) $request->query('warehouse', '');
        $product = (string) $request->query('product', '');
        abort_if($warehouse !== '' && ! in_array($warehouse, $warehouseIds, true), 403);

        $movements = InventoryMovement::query()
            ->with(['product:id,sku,name', 'warehouse:id,code', 'operation:id,type,reason,source_type,source_id,created_by', 'operation.author:id,name'])
            ->whereIn('warehouse_id', $warehouse !== '' ? [$warehouse] : $warehouseIds)
            ->when($product !== '', fn ($q) => $q->where('product_id', $product))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'occurred_at' => $m->occurred_at->toIso8601String(),
                'type' => $m->type,
                'product' => $m->product->only(['id', 'sku', 'name']),
                'warehouse' => $m->warehouse->code,
                'delta' => Quantity::of($m->quantity_delta)->format(),
                'positive' => Quantity::of($m->quantity_delta)->isPositive(),
                'balance_after' => Quantity::of($m->balance_after)->format(),
                'reason' => $m->operation->reason,
                'author' => $m->operation->author->name,
            ]);

        return Inertia::render('inventory/Movements', [
            'movements' => $movements,
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'filters' => ['warehouse' => $warehouse, 'product' => $product],
            'product' => $product !== '' ? Product::query()->find($product)?->only(['id', 'sku', 'name']) : null,
        ]);
    }

    /**
     * Stock initial ou ajustement, selon le champ « mode ».
     */
    public function adjust(Request $request, AdjustmentService $service): RedirectResponse
    {
        Gate::authorize('inventory.adjust');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['initial', 'adjustment'])],
            'warehouse_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:mode,adjustment'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
        ], attributes: [
            'reason' => 'motif',
            'lines' => 'articles',
            'lines.*.quantity' => 'quantité',
            'lines.*.unit_cost' => 'coût unitaire',
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $lines = array_map(fn (array $l) => [
                'product_id' => (string) $l['product_id'],
                'quantity' => (string) $l['quantity'],
                'unit_cost' => isset($l['unit_cost']) ? (int) $l['unit_cost'] : null,
            ], $data['lines']);

            if ($data['mode'] === 'initial') {
                $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->pluck('standard_cost', 'id');
                $service->initial((string) $data['warehouse_id'], array_values(array_map(fn ($l) => [...$l, 'unit_cost' => $l['unit_cost'] ?? (int) ($products[$l['product_id']] ?? 0)], $lines)), $data['reason'] ?? null, $user, (string) $data['idempotency_key']);
            } else {
                $service->adjust((string) $data['warehouse_id'], array_values($lines), (string) $data['reason'], $user, (string) $data['idempotency_key']);
            }
        } catch (InvalidArgumentException $e) {
            return back(303)->withErrors(['lines' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __("inventory.saved.{$data['mode']}")]);

        return back(303);
    }

    /**
     * Seuil d'alerte d'un article dans un dépôt (vide = pas d'alerte).
     */
    public function reorderPoint(Request $request, Product $product, StockAccess $access, AuditLog $audit, TenantContext $context): RedirectResponse
    {
        Gate::authorize('inventory.adjust');

        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'reorder_point' => ['nullable', 'string', 'max:20'],
        ]);

        $warehouse = $access->warehouse((string) $data['warehouse_id']);

        try {
            $value = blank($data['reorder_point']) ? null : Quantity::of((string) $data['reorder_point']);
        } catch (InvalidArgumentException $e) {
            return back(303)->withErrors(['reorder_point' => $e->getMessage()]);
        }

        if ($value?->isNegative()) {
            return back(303)->withErrors(['reorder_point' => __('inventory.errors.positive_quantity')]);
        }

        DB::transaction(function () use ($product, $warehouse, $value, $audit, $context): void {
            DB::table('product_warehouse_settings')->upsert([
                'tenant_id' => $context->tenantId(),
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'reorder_point' => $value?->value,
                'created_at' => now(),
                'updated_at' => now(),
            ], ['product_id', 'warehouse_id'], ['reorder_point', 'updated_at']);

            $audit->record('stock.reorder_point_set', $product, after: ['warehouse' => $warehouse->code, 'reorder_point' => $value?->value]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.reorder_point')]);

        return back(303);
    }

    private function lowStockCount(string $warehouseId): int
    {
        return DB::table('product_warehouse_settings as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
            ->where('s.warehouse_id', $warehouseId)
            ->where('p.tenant_id', app(TenantContext::class)->tenantId())
            ->whereNull('p.archived_at')
            ->whereNotNull('s.reorder_point')
            ->whereRaw('COALESCE(b.quantity_on_hand, 0) <= s.reorder_point')
            ->count();
    }
}
