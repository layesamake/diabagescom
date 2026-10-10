<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\TransferService;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class TransferController extends Controller
{
    public function index(StockAccess $access): Response
    {
        Gate::authorize('inventory.view');
        $ids = $access->accessibleIds();

        $transfers = StockTransfer::query()
            ->with(['source:id,code,name', 'destination:id,code,name'])
            ->withCount('lines')
            ->where(fn ($q) => $q->whereIn('source_warehouse_id', $ids)->orWhereIn('destination_warehouse_id', $ids))
            ->latest()
            ->paginate(25)
            ->through(fn (StockTransfer $t) => $this->summary($t));

        return Inertia::render('inventory/Transfers', [
            'transfers' => $transfers,
            'canCreate' => Gate::allows('inventory.transfer_create'),
        ]);
    }

    public function create(StockAccess $access): Response
    {
        Gate::authorize('inventory.transfer_create');

        return Inertia::render('inventory/TransferForm', [
            'sources' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'destinations' => Warehouse::query()->where('status', Warehouse::STATUS_ACTIVE)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, TransferService $service): RedirectResponse
    {
        Gate::authorize('inventory.transfer_create');

        $data = $request->validate([
            'source_warehouse_id' => ['required', 'uuid'],
            'destination_warehouse_id' => ['required', 'uuid', 'different:source_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
        ], attributes: ['destination_warehouse_id' => 'dépôt de destination', 'lines' => 'articles']);

        /** @var User $user */
        $user = $request->user();

        try {
            $transfer = $service->create(
                (string) $data['source_warehouse_id'],
                (string) $data['destination_warehouse_id'],
                array_values(array_map(fn ($l) => ['product_id' => (string) $l['product_id'], 'quantity' => (string) $l['quantity']], $data['lines'])),
                $data['notes'] ?? null,
                $user,
            );
        } catch (InvalidArgumentException $e) {
            return back(303)->withErrors(['lines' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.transfer_created')]);

        return to_route('transfers.show', $transfer);
    }

    public function show(StockTransfer $transfer, StockAccess $access): Response
    {
        Gate::authorize('inventory.view');
        $ids = $access->accessibleIds();
        abort_unless(in_array($transfer->source_warehouse_id, $ids, true) || in_array($transfer->destination_warehouse_id, $ids, true), 404);

        $transfer->load(['source:id,code,name', 'destination:id,code,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code,decimals_allowed']);

        return Inertia::render('inventory/TransferShow', [
            'transfer' => [
                ...$this->summary($transfer),
                'notes' => $transfer->notes,
                'receipt_note' => $transfer->receipt_note,
                'lines' => $transfer->lines->map(fn (StockTransferLine $l) => [
                    'product_id' => $l->product_id,
                    'sku' => $l->product->sku,
                    'name' => $l->product->name,
                    'unit' => $l->product->unit->code,
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'quantity_raw' => $l->quantity,
                    'quantity_received' => $l->quantity_received !== null ? Quantity::of($l->quantity_received)->format() : null,
                ])->values(),
            ],
            'can' => [
                'ship' => $transfer->status === StockTransfer::DRAFT && Gate::allows('inventory.transfer_ship') && in_array($transfer->source_warehouse_id, $ids, true),
                'receive' => $transfer->status === StockTransfer::IN_TRANSIT && Gate::allows('inventory.transfer_receive') && in_array($transfer->destination_warehouse_id, $ids, true),
                'cancel' => $transfer->status === StockTransfer::DRAFT && Gate::allows('inventory.transfer_create'),
            ],
        ]);
    }

    public function ship(Request $request, StockTransfer $transfer, TransferService $service): RedirectResponse
    {
        Gate::authorize('inventory.transfer_ship');
        $key = (string) $request->validate(['idempotency_key' => ['required', 'uuid']])['idempotency_key'];

        /** @var User $user */
        $user = $request->user();
        $transfer = $service->ship($transfer, $user, $key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.transfer_shipped', ['number' => $transfer->number])]);

        return back(303);
    }

    public function receive(Request $request, StockTransfer $transfer, TransferService $service): RedirectResponse
    {
        Gate::authorize('inventory.transfer_receive');

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var array<string, string> $received */
        $received = array_filter($data['received'] ?? [], fn ($v) => $v !== null && $v !== '');

        try {
            $transfer = $service->receive($transfer, $received, $data['note'] ?? null, $user, (string) $data['idempotency_key']);
        } catch (InvalidArgumentException $e) {
            return back(303)->withErrors(['received' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.transfer_received', ['number' => $transfer->number])]);

        return back(303);
    }

    public function cancel(Request $request, StockTransfer $transfer, TransferService $service): RedirectResponse
    {
        Gate::authorize('inventory.transfer_create');

        /** @var User $user */
        $user = $request->user();
        $service->cancel($transfer, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.saved.transfer_cancelled')]);

        return back(303);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'status' => $transfer->status,
            'source' => $transfer->source->only(['id', 'code', 'name']),
            'destination' => $transfer->destination->only(['id', 'code', 'name']),
            'lines_count' => $transfer->lines_count ?? $transfer->lines()->count(),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'shipped_at' => $transfer->shipped_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
        ];
    }
}
