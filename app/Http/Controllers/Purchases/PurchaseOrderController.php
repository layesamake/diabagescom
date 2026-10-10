<?php

namespace App\Http\Controllers\Purchases;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\PurchaseOrderService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        $status = (string) $request->query('status', '');

        $orders = PurchaseOrder::query()->with(['supplier:id,code,name', 'warehouse:id,code,name'])
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [PurchaseOrder::CONFIRMED, PurchaseOrder::PARTIALLY_RECEIVED]))
            ->when(in_array($status, ['draft', 'received', 'closed', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->latest()->paginate(25)->withQueryString()
            ->through(fn (PurchaseOrder $o) => $this->summary($o));

        return Inertia::render('purchases/Orders', [
            'orders' => $orders,
            'filters' => ['status' => $status],
            'can' => ['create' => Gate::allows('purchase.create')],
        ]);
    }

    public function create(StockAccess $access): Response
    {
        Gate::authorize('purchase.create');

        return Inertia::render('purchases/OrderForm', ['order' => null, ...$this->formData($access)]);
    }

    public function store(Request $request, PurchaseOrderService $orders): RedirectResponse
    {
        Gate::authorize('purchase.create');

        /** @var User $user */
        $user = $request->user();
        $order = $orders->saveDraft(null, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.order_draft')]);

        return to_route('purchases.orders.show', $order);
    }

    public function edit(PurchaseOrder $order, StockAccess $access): Response|RedirectResponse
    {
        Gate::authorize('purchase.update_draft');
        $this->authorizeWarehouse($order, $access);

        if ($order->status !== PurchaseOrder::DRAFT) {
            return to_route('purchases.orders.show', $order);
        }

        $order->load(['lines.product.unit', 'supplier:id,code,name']);

        return Inertia::render('purchases/OrderForm', [
            'order' => [
                'id' => $order->id,
                'version' => $order->version,
                'warehouse_id' => $order->warehouse_id,
                'supplier_id' => $order->supplier_id,
                'expected_on' => $order->expected_on?->toDateString(),
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn (PurchaseOrderLine $l) => [
                    'product' => ['id' => $l->product_id, 'sku' => $l->sku, 'name' => $l->label, 'unit' => $l->unit_code, 'decimals' => $l->product->unit->decimals_allowed, 'tax_rate_basis_points' => $l->tax_rate_basis_points],
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'unit_cost' => $l->unit_cost,
                ])->values(),
            ],
            ...$this->formData($access),
        ]);
    }

    public function update(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        Gate::authorize('purchase.update_draft');

        /** @var User $user */
        $user = $request->user();
        $orders->saveDraft($order, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.order_draft')]);

        return to_route('purchases.orders.show', $order);
    }

    public function show(PurchaseOrder $order, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        $this->authorizeWarehouse($order, $access);
        $order->load(['lines.product.unit', 'supplier:id,code,name,phone', 'warehouse:id,code,name', 'receipts', 'invoices:id,number,status,total,purchase_order_id,supplier_reference']);
        $receivable = in_array($order->status, [PurchaseOrder::CONFIRMED, PurchaseOrder::PARTIALLY_RECEIVED], true);

        return Inertia::render('purchases/OrderShow', [
            'order' => [
                ...$this->summary($order),
                'expected_on' => $order->expected_on?->toDateString(),
                'notes' => $order->notes,
                'cancel_reason' => $order->cancel_reason,
                'total_excl_tax' => $order->total_excl_tax,
                'total_tax' => $order->total_tax,
                'lines' => $order->lines->map(fn (PurchaseOrderLine $l) => [
                    'id' => $l->id,
                    'sku' => $l->sku,
                    'label' => $l->label,
                    'unit' => $l->unit_code,
                    'decimals' => $l->product->unit->decimals_allowed,
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'received' => Quantity::of($l->quantity_received)->format(),
                    'remaining' => Quantity::of($l->quantity)->sub(Quantity::of($l->quantity_received))->format(),
                    'remaining_raw' => Quantity::of($l->quantity)->sub(Quantity::of($l->quantity_received))->value,
                    'unit_cost' => $l->unit_cost,
                    'tax_rate' => $l->tax_rate_basis_points,
                    'total' => $l->line_total,
                ])->values(),
                'receipts' => $order->receipts->sortBy('received_at')->map(fn (GoodsReceipt $r) => [
                    'id' => $r->id, 'number' => $r->number, 'received_at' => $r->received_at->toIso8601String(), 'total_excl_tax' => $r->total_excl_tax,
                ])->values(),
                'invoices' => $order->invoices->map->only(['id', 'number', 'status', 'total', 'supplier_reference'])->values(),
            ],
            'can' => [
                'edit' => $order->status === PurchaseOrder::DRAFT && Gate::allows('purchase.update_draft'),
                'confirm' => $order->status === PurchaseOrder::DRAFT && Gate::allows('purchase.validate'),
                'receive' => $receivable && Gate::allows('purchase.receive'),
                'cancel' => in_array($order->status, [PurchaseOrder::DRAFT, PurchaseOrder::CONFIRMED, PurchaseOrder::PARTIALLY_RECEIVED], true) && Gate::allows('purchase.cancel'),
                'invoice' => $order->status !== PurchaseOrder::DRAFT && $order->status !== PurchaseOrder::CANCELLED && Gate::allows('purchase.create'),
            ],
        ]);
    }

    public function confirm(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        Gate::authorize('purchase.validate');

        /** @var User $user */
        $user = $request->user();
        $order = $orders->confirm($order, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.order_confirmed', ['number' => $order->number])]);

        return to_route('purchases.orders.show', $order);
    }

    public function cancel(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        Gate::authorize('purchase.cancel');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $order = $orders->cancel($order, $data['reason'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($order->status === PurchaseOrder::CLOSED ? 'purchases.saved.order_closed' : 'purchases.saved.order_cancelled')]);

        return to_route('purchases.orders.show', $order);
    }

    public function receive(Request $request, PurchaseOrder $order, GoodsReceiptService $receipts): RedirectResponse
    {
        Gate::authorize('purchase.receive');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'supplier_reference' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
            'quantities' => ['required', 'array', 'max:200'],
            'quantities.*' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var array<string, string> $quantities */
        $quantities = array_map(fn ($q) => (string) $q, $data['quantities']);
        $receipt = $receipts->receiveOrder($order, $quantities, $data['supplier_reference'] ?? null, $data['note'] ?? null, $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.receipt', ['number' => $receipt->number])]);

        return to_route('purchases.receipts.show', $receipt);
    }

    /**
     * @return array{warehouse_id: string, supplier_id: string, expected_on: string|null, notes: string|null, version: int|null, lines: list<array{product_id: string, quantity: string, unit_cost: int}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'expected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], attributes: ['supplier_id' => 'fournisseur', 'lines' => 'articles']);

        return [
            'warehouse_id' => (string) $data['warehouse_id'],
            'supplier_id' => (string) $data['supplier_id'],
            'expected_on' => isset($data['expected_on']) ? (string) $data['expected_on'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'lines' => array_values(array_map(fn (array $l) => ['product_id' => (string) $l['product_id'], 'quantity' => (string) $l['quantity'], 'unit_cost' => (int) $l['unit_cost']], $data['lines'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(StockAccess $access): array
    {
        return [
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'suppliers' => Supplier::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    private function authorizeWarehouse(PurchaseOrder $order, StockAccess $access): void
    {
        abort_unless(in_array($order->warehouse_id, $access->accessibleIds(), true), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'supplier' => $order->supplier->only(['id', 'code', 'name']),
            'warehouse' => $order->warehouse->only(['id', 'code', 'name']),
            'total' => $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
