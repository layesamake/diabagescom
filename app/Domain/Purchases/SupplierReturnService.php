<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\StockLedger;
use App\Domain\Organization\Sequences;
use App\Domain\Sales\Pricing;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\SupplierInvoice;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Retour fournisseur depuis un bon de réception : sortie de stock (jamais au-delà
 * du stock disponible) et avoir imputé sur la facture fournisseur validée liée
 * à cette réception. Sans facture, les quantités retournées sont déduites quand
 * la facture est préparée depuis la réception.
 */
final class SupplierReturnService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
        private readonly SupplierInvoiceService $invoices,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, string>  $quantities  receipt_line_id => quantité retournée
     */
    public function create(GoodsReceipt $receipt, array $quantities, string $reason, User $actor, string $idempotencyKey): SupplierReturn
    {
        $this->access->assertCanWrite($this->context->tenant());

        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidPurchaseOperation(__('purchases.errors.reason_required'));
        }

        $existing = SupplierReturn::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($receipt, $quantities, $reason, $actor, $idempotencyKey): SupplierReturn {
                // Verrou de la réception : deux retours simultanés ne dépassent pas la quantité reçue.
                /** @var GoodsReceipt $receipt */
                $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                $this->stockAccess->warehouse($receipt->warehouse_id);

                $receiptLines = GoodsReceiptLine::query()->where('receipt_id', $receipt->id)->get()->keyBy('id');
                $returned = SupplierReturnLine::query()->whereIn('receipt_line_id', $receiptLines->keys())
                    ->selectRaw('receipt_line_id, SUM(quantity) AS quantity')->groupBy('receipt_line_id')->pluck('quantity', 'receipt_line_id');

                $invoice = SupplierInvoice::query()->where('goods_receipt_id', $receipt->id)
                    ->where('status', SupplierInvoice::VALIDATED)->first();

                $return = new SupplierReturn;
                $return->forceFill([
                    'number' => $this->sequences->document('purchase:return', 'RF'),
                    'supplier_id' => $receipt->supplier_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'goods_receipt_id' => $receipt->id,
                    'supplier_invoice_id' => $invoice?->id,
                    'reason' => trim($reason),
                    'total_excl_tax' => 0, 'total_tax' => 0, 'total' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actor->id,
                ]);

                $lines = [];
                $movements = [];
                $totals = ['excl' => 0, 'tax' => 0];

                foreach ($quantities as $lineId => $raw) {
                    if (trim((string) $raw) === '' || trim((string) $raw) === '0') {
                        continue;
                    }

                    /** @var GoodsReceiptLine|null $receiptLine */
                    $receiptLine = $receiptLines[$lineId] ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_line'));
                    $quantity = Quantity::of((string) $raw);
                    $remaining = Quantity::of($receiptLine->quantity)->sub(Quantity::of((string) ($returned[$lineId] ?? '0')));

                    if (! $quantity->isPositive() || $quantity->compare($remaining) > 0) {
                        throw new InvalidPurchaseOperation(__('purchases.errors.return_exceeds', ['product' => $receiptLine->label, 'max' => $remaining->format()]));
                    }

                    $amounts = Pricing::purchaseLine($quantity, $receiptLine->unit_cost, $receiptLine->tax_rate_basis_points);
                    $lines[] = [
                        'receipt_line_id' => $receiptLine->id,
                        'product_id' => $receiptLine->product_id,
                        'quantity' => $quantity->value,
                        'unit_cost' => $receiptLine->unit_cost,
                        'tax_rate_basis_points' => $receiptLine->tax_rate_basis_points,
                        'line_excl_tax' => $amounts['excl_tax'],
                        'line_tax' => $amounts['tax'],
                    ];
                    $totals['excl'] += $amounts['excl_tax'];
                    $totals['tax'] += $amounts['tax'];
                    $movements[] = new MovementLine($receipt->warehouse_id, $receiptLine->product_id, $quantity->negate());
                }

                if ($lines === []) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
                }

                $return->total_excl_tax = $totals['excl'];
                $return->total_tax = $totals['tax'];
                $return->total = $totals['excl'] + $totals['tax'];
                $return->save();

                foreach ($lines as $line) {
                    (new SupplierReturnLine)->forceFill([...$line, 'return_id' => $return->id])->save();
                }

                $this->ledger->post('supplier_return', "supplier_return:{$return->id}", $movements, $actor, source: $return, reason: trim($reason));

                if ($invoice !== null) {
                    $invoice = $this->invoices->lock($invoice);
                    $invoice->amount_credited = min($invoice->total, $invoice->amount_credited + $return->total);
                    SupplierInvoiceService::refreshPaymentStatus($invoice);
                    $invoice->version++;
                    $invoice->save();
                }

                $this->audit->record('purchase.supplier_return', $return,
                    after: ['number' => $return->number, 'receipt' => $receipt->number, 'invoice' => $invoice?->number, 'total' => $return->total],
                    metadata: ['reason' => trim($reason)], actor: $actor);

                return $return;
            });
        } catch (UniqueConstraintViolationException $e) {
            return SupplierReturn::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }
}
