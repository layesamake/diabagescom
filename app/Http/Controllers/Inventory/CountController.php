<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\CountService;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CountController extends Controller
{
    public function index(StockAccess $access): Response
    {
        Gate::authorize('inventory.view');

        $counts = InventoryCount::query()
            ->with('warehouse:id,code,name')
            ->withCount('lines')
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->latest()
            ->paginate(25)
            ->through(fn (InventoryCount $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'warehouse' => $c->warehouse->only(['code', 'name']),
                'lines_count' => $c->lines_count,
                'created_at' => $c->created_at?->toIso8601String(),
                'validated_at' => $c->validated_at?->toIso8601String(),
            ]);

        return Inertia::render('inventory/Counts', [
            'counts' => $counts,
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'canCreate' => Gate::allows('inventory.count_create'),
        ]);
    }

    public function store(Request $request, CountService $service): RedirectResponse
    {
        Gate::authorize('inventory.count_create');

        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $count = $service->create((string) $data['warehouse_id'], $data['notes'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.count_created')]);

        return to_route('counts.show', $count);
    }

    public function show(InventoryCount $count, StockAccess $access): Response
    {
        Gate::authorize('inventory.view');
        abort_unless(in_array($count->warehouse_id, $access->accessibleIds(), true), 404);

        $count->load(['warehouse:id,code,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code,decimals_allowed']);

        $current = DB::table('inventory_balances')
            ->where('warehouse_id', $count->warehouse_id)
            ->whereIn('product_id', $count->lines->pluck('product_id'))
            ->pluck('quantity_on_hand', 'product_id');

        return Inertia::render('inventory/CountShow', [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'status' => $count->status,
                'warehouse' => $count->warehouse->only(['id', 'code', 'name']),
                'notes' => $count->notes,
                'validated_at' => $count->validated_at?->toIso8601String(),
                'lines' => $count->lines->sortBy(fn (InventoryCountLine $l) => $l->product->name)->map(function (InventoryCountLine $l) use ($current, $count) {
                    // Brouillon : écart indicatif sur le stock actuel ; validé : écart figé.
                    $theoretical = $count->status === InventoryCount::VALIDATED
                        ? Quantity::of((string) $l->theoretical_quantity)
                        : Quantity::of((string) ($current[$l->product_id] ?? '0'));
                    $variance = Quantity::of($l->counted_quantity)->sub($theoretical);

                    return [
                        'id' => $l->id,
                        'product_id' => $l->product_id,
                        'sku' => $l->product->sku,
                        'name' => $l->product->name,
                        'unit' => $l->product->unit->code,
                        'counted' => Quantity::of($l->counted_quantity)->format(),
                        'theoretical' => $theoretical->format(),
                        'variance' => $variance->format(),
                        'has_variance' => ! $variance->isZero(),
                        'reason' => $l->reason,
                    ];
                })->values(),
            ],
            'can' => [
                'edit' => $count->status === InventoryCount::DRAFT && Gate::allows('inventory.count_create'),
                'validate' => $count->status === InventoryCount::DRAFT && Gate::allows('inventory.count_validate'),
            ],
        ]);
    }

    public function setLine(Request $request, InventoryCount $count, CountService $service): RedirectResponse
    {
        Gate::authorize('inventory.count_create');

        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'counted_quantity' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], attributes: ['counted_quantity' => 'quantité comptée', 'reason' => 'motif']);

        try {
            $service->setLine($count, (string) $data['product_id'], (string) $data['counted_quantity'], $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back(303)->withErrors(['counted_quantity' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.count_line')]);

        return back(303);
    }

    public function removeLine(InventoryCount $count, InventoryCountLine $line, CountService $service): RedirectResponse
    {
        Gate::authorize('inventory.count_create');
        $service->removeLine($count, $line);

        return back(303);
    }

    public function validateCount(Request $request, InventoryCount $count, CountService $service): RedirectResponse
    {
        Gate::authorize('inventory.count_validate');
        $key = (string) $request->validate(['idempotency_key' => ['required', 'uuid']])['idempotency_key'];

        /** @var User $user */
        $user = $request->user();
        $count = $service->validate($count, $user, $key);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.count_validated', ['number' => $count->number])]);

        return back(303);
    }

    public function cancel(Request $request, InventoryCount $count, CountService $service): RedirectResponse
    {
        Gate::authorize('inventory.count_create');

        /** @var User $user */
        $user = $request->user();
        $service->cancel($count, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.count_cancelled')]);

        return back(303);
    }
}
