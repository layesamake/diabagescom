<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\Quantity;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 6 : commandes fournisseurs à recevoir.
 *
 * La réception elle-même reste portée par GoodsReceiptService, commun au mode
 * expert. Cet écran ne montre que les commandes des dépôts accessibles.
 */
class SimplePurchaseController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(StockAccess $access): Response
    {
        Gate::authorize('purchase.view');

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->whereIn('status', [
                PurchaseOrder::CONFIRMED,
                PurchaseOrder::PARTIALLY_RECEIVED,
                PurchaseOrder::RECEIVED,
            ])
            ->latest()
            ->get()
            ->map(fn (PurchaseOrder $order): array => $this->summary($order));

        return Inertia::render('simple/Achats', [
            'open_orders' => $orders
                ->whereIn('status', [
                    PurchaseOrder::CONFIRMED,
                    PurchaseOrder::PARTIALLY_RECEIVED,
                ])
                ->values(),
            'received_orders' => $orders
                ->where('status', PurchaseOrder::RECEIVED)
                ->values(),
            'audio_code' => 'ACH-01',
        ]);
    }

    public function show(PurchaseOrder $order, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        abort_unless(in_array($order->warehouse_id, $access->accessibleIds(), true), 404);

        $order->load(['supplier:id,name', 'lines.product.unit']);
        $receivable = in_array($order->status, [
            PurchaseOrder::CONFIRMED,
            PurchaseOrder::PARTIALLY_RECEIVED,
        ], true);

        return Inertia::render('simple/Achat', [
            'order' => [
                ...$this->summary($order),
                'supplier' => $order->supplier->only(['id', 'name']),
                'lines' => $order->lines->map(function ($line): array {
                    $remaining = Quantity::of($line->quantity)
                        ->sub(Quantity::of($line->quantity_received));

                    return [
                        'id' => $line->id,
                        'product_id' => $line->product_id,
                        'name' => $line->label,
                        'unit' => $line->unit_code,
                        'quantity' => Quantity::of($line->quantity)->format(),
                        'received' => Quantity::of($line->quantity_received)->format(),
                        'remaining' => $remaining->format(),
                    ];
                })->values(),
            ],
            'can' => ['receive' => $receivable && Gate::allows('purchase.receive')],
            'audio_code' => 'ACH-05',
        ]);
    }

    /**
     * @return array{id: string, number: string|null, status: string, supplier: string, total: int, expected_on: string|null}
     */
    private function summary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'supplier' => $order->supplier->name,
            'total' => $order->total,
            'expected_on' => $order->expected_on?->toDateString(),
        ];
    }
}
