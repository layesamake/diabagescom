<?php

namespace App\Http\Controllers\Purchases;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Purchases\SupplierReturnService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\SupplierInvoice;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function show(GoodsReceipt $receipt, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        abort_unless(in_array($receipt->warehouse_id, $access->accessibleIds(), true), 404);

        $receipt->load(['lines', 'supplier:id,code,name', 'warehouse:id,code,name', 'purchaseOrder:id,number', 'author:id,name', 'returns.author:id,name']);
        $returned = SupplierReturnLine::query()->whereIn('receipt_line_id', $receipt->lines->pluck('id'))
            ->selectRaw('receipt_line_id, SUM(quantity) AS quantity')->groupBy('receipt_line_id')->pluck('quantity', 'receipt_line_id');
        $invoice = SupplierInvoice::query()->where('goods_receipt_id', $receipt->id)->where('status', '<>', SupplierInvoice::CANCELLED)->first(['id', 'number', 'status']);

        return Inertia::render('purchases/ReceiptShow', [
            'receipt' => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'supplier' => $receipt->supplier->only(['id', 'code', 'name']),
                'warehouse' => $receipt->warehouse->only(['id', 'code', 'name']),
                'order' => $receipt->purchaseOrder?->only(['id', 'number']),
                'supplier_reference' => $receipt->supplier_reference,
                'note' => $receipt->note,
                'total_excl_tax' => $receipt->total_excl_tax,
                'received_at' => $receipt->received_at->toIso8601String(),
                'author' => $receipt->author->name,
                'invoice' => $invoice?->only(['id', 'number', 'status']),
                'lines' => $receipt->lines->map(function (GoodsReceiptLine $l) use ($returned) {
                    $remaining = Quantity::of($l->quantity)->sub(Quantity::of((string) ($returned[$l->id] ?? '0')));

                    return [
                        'id' => $l->id,
                        'sku' => $l->sku,
                        'label' => $l->label,
                        'unit' => $l->unit_code,
                        'quantity' => Quantity::of($l->quantity)->format(),
                        'returned' => Quantity::of((string) ($returned[$l->id] ?? '0'))->format(),
                        'returnable' => $remaining->format(),
                        'returnable_raw' => $remaining->value,
                        'unit_cost' => $l->unit_cost,
                        'total' => $l->line_excl_tax,
                    ];
                })->values(),
                'returns' => $receipt->returns->map(fn (SupplierReturn $r) => [
                    'id' => $r->id, 'number' => $r->number, 'reason' => $r->reason, 'total' => $r->total,
                    'created_at' => $r->created_at->toIso8601String(), 'author' => $r->author->name,
                ])->values(),
            ],
            'can' => [
                'return' => Gate::allows('purchase.validate'),
                'invoice' => $invoice === null && Gate::allows('purchase.create'),
            ],
        ]);
    }

    public function storeReturn(Request $request, GoodsReceipt $receipt, SupplierReturnService $returns): RedirectResponse
    {
        Gate::authorize('purchase.validate');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'quantities' => ['required', 'array', 'max:200'],
            'quantities.*' => ['nullable', 'string', 'max:20'],
        ], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        /** @var array<string, string> $quantities */
        $quantities = array_map(fn ($q) => (string) $q, $data['quantities']);
        $return = $returns->create($receipt, $quantities, (string) $data['reason'], $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.return', ['number' => $return->number])]);

        return to_route('purchases.receipts.show', $receipt);
    }
}
