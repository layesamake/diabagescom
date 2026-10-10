<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Sales\Pricing;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierReturnLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Factures fournisseurs. Politique retenue (à confirmer par l'expert-comptable) :
 * la dette naît à la validation de la facture fournisseur, pas à la réception.
 * Une même référence fournisseur ne peut être validée deux fois (double saisie).
 */
final class SupplierInvoiceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{supplier_id: string, warehouse_id: string, supplier_reference?: string|null, issue_date?: string|null, due_date?: string|null, purchase_order_id?: string|null, goods_receipt_id?: string|null, notes?: string|null, version?: int|null, lines: list<array{product_id?: string|null, label: string, quantity: string, unit_cost: int, tax_rate_basis_points: int}>}  $data
     */
    public function saveDraft(?SupplierInvoice $invoice, array $data, User $actor): SupplierInvoice
    {
        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($data['warehouse_id']);
        $supplier = PurchaseOrderService::supplier($data['supplier_id']);

        return DB::transaction(function () use ($invoice, $data, $actor, $warehouse, $supplier): SupplierInvoice {
            if ($invoice !== null) {
                $invoice = $this->lock($invoice);

                if ($invoice->status !== SupplierInvoice::DRAFT) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.not_draft'));
                }

                if (isset($data['version']) && $data['version'] !== $invoice->version) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.stale'));
                }

                $invoice->lines()->delete();
                $invoice->version++;
            } else {
                $invoice = new SupplierInvoice;
                $invoice->created_by = $actor->id;
            }

            $order = filled($data['purchase_order_id'] ?? null) ? PurchaseOrder::query()->find($data['purchase_order_id']) : null;
            $receipt = filled($data['goods_receipt_id'] ?? null) ? GoodsReceipt::query()->find($data['goods_receipt_id']) : null;

            if (($order !== null && $order->supplier_id !== $supplier->id) || ($receipt !== null && $receipt->supplier_id !== $supplier->id)) {
                throw new InvalidPurchaseOperation(__('purchases.errors.other_supplier'));
            }

            $invoice->forceFill([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'supplier_reference' => filled($data['supplier_reference'] ?? null) ? trim((string) $data['supplier_reference']) : null,
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'purchase_order_id' => $order->id ?? $receipt?->purchase_order_id,
                'goods_receipt_id' => $receipt?->id,
                'notes' => $data['notes'] ?? null,
            ])->save();

            if ($data['lines'] === []) {
                throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
            }

            $totals = ['excl' => 0, 'tax' => 0];

            foreach ($data['lines'] as $position => $input) {
                try {
                    $quantity = Quantity::of($input['quantity']);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidPurchaseOperation($e->getMessage());
                }

                if (! $quantity->isPositive() || $input['unit_cost'] < 0 || $input['tax_rate_basis_points'] < 0 || $input['tax_rate_basis_points'] > 10000 || trim($input['label']) === '') {
                    throw new InvalidPurchaseOperation(__('purchases.errors.invalid_line', ['line' => $position + 1]));
                }

                $amounts = Pricing::purchaseLine($quantity, $input['unit_cost'], $input['tax_rate_basis_points']);

                (new SupplierInvoiceLine)->forceFill([
                    'invoice_id' => $invoice->id,
                    'position' => $position + 1,
                    'product_id' => $input['product_id'] ?? null,
                    'label' => trim($input['label']),
                    'quantity' => $quantity->value,
                    'unit_cost' => $input['unit_cost'],
                    'tax_rate_basis_points' => $input['tax_rate_basis_points'],
                    'line_excl_tax' => $amounts['excl_tax'],
                    'line_tax' => $amounts['tax'],
                    'line_total' => $amounts['total'],
                ])->save();

                $totals['excl'] += $amounts['excl_tax'];
                $totals['tax'] += $amounts['tax'];
            }

            $invoice->total_excl_tax = $totals['excl'];
            $invoice->total_tax = $totals['tax'];
            $invoice->total = $totals['excl'] + $totals['tax'];
            $invoice->save();

            $this->audit->record('purchase.invoice_draft_saved', $invoice, after: ['supplier' => $supplier->code, 'total' => $invoice->total], actor: $actor);

            return $invoice;
        });
    }

    /**
     * Lignes proposées depuis un bon de réception (quantités nettes des retours déjà faits).
     *
     * @return list<array{product_id: string, label: string, quantity: string, unit_cost: int, tax_rate_basis_points: int}>
     */
    public function linesFromReceipt(GoodsReceipt $receipt): array
    {
        $returned = SupplierReturnLine::query()->whereIn('receipt_line_id', $receipt->lines()->select('id'))
            ->selectRaw('receipt_line_id, SUM(quantity) AS quantity')->groupBy('receipt_line_id')->pluck('quantity', 'receipt_line_id');
        $lines = [];

        foreach ($receipt->lines()->get() as $line) {
            $quantity = Quantity::of($line->quantity)->sub(Quantity::of((string) ($returned[$line->id] ?? '0')));

            if ($quantity->isPositive()) {
                $lines[] = ['product_id' => $line->product_id, 'label' => $line->label, 'quantity' => $quantity->value,
                    'unit_cost' => $line->unit_cost, 'tax_rate_basis_points' => $line->tax_rate_basis_points];
            }
        }

        return $lines;
    }

    public function validate(SupplierInvoice $invoice, User $actor): SupplierInvoice
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($invoice, $actor): SupplierInvoice {
            $invoice = $this->lock($invoice);
            $this->stockAccess->warehouse($invoice->warehouse_id);

            if ($invoice->status === SupplierInvoice::VALIDATED) {
                return $invoice;
            }

            if ($invoice->status !== SupplierInvoice::DRAFT) {
                throw new InvalidPurchaseOperation(__('purchases.errors.not_draft'));
            }

            if (! $invoice->lines()->exists()) {
                throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
            }

            if ($invoice->supplier_reference === null) {
                throw new InvalidPurchaseOperation(__('purchases.errors.reference_required'));
            }

            $duplicate = SupplierInvoice::query()->where('supplier_id', $invoice->supplier_id)
                ->where('status', SupplierInvoice::VALIDATED)
                ->whereRaw('upper(supplier_reference) = upper(?)', [$invoice->supplier_reference])
                ->value('number');

            if ($duplicate !== null) {
                throw new InvalidPurchaseOperation(__('purchases.errors.duplicate_reference', ['number' => $duplicate]));
            }

            $issue = $invoice->issue_date ?? CarbonImmutable::today();
            $invoice->issue_date = $issue;
            $invoice->due_date ??= $issue->addDays($invoice->supplier->payment_term_days);
            $invoice->number = $this->sequences->document('purchase:invoice', 'FF');
            $invoice->status = SupplierInvoice::VALIDATED;
            $invoice->payment_status = $invoice->total === 0 ? 'paid' : 'unpaid';
            $invoice->validated_by = $actor->id;
            $invoice->validated_at = now();
            $invoice->version++;
            $invoice->save();

            $this->audit->record('purchase.invoice_validated', $invoice,
                after: ['number' => $invoice->number, 'supplier_reference' => $invoice->supplier_reference, 'total' => $invoice->total], actor: $actor);

            return $invoice;
        });
    }

    public function cancel(SupplierInvoice $invoice, User $actor): SupplierInvoice
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($invoice, $actor): SupplierInvoice {
            $invoice = $this->lock($invoice);

            if ($invoice->status !== SupplierInvoice::DRAFT) {
                throw new InvalidPurchaseOperation(__('purchases.errors.invoice_cancel'));
            }

            $invoice->status = SupplierInvoice::CANCELLED;
            $invoice->cancelled_by = $actor->id;
            $invoice->cancelled_at = now();
            $invoice->version++;
            $invoice->save();

            $this->audit->record('purchase.invoice_cancelled', $invoice, actor: $actor);

            return $invoice;
        });
    }

    public static function refreshPaymentStatus(SupplierInvoice $invoice): void
    {
        $balance = $invoice->balanceDue();

        $invoice->payment_status = match (true) {
            $balance < 0 => 'refund_due',
            $balance === 0 => 'paid',
            $invoice->amount_paid + $invoice->amount_credited === 0 => 'unpaid',
            default => 'partial',
        };
    }

    public function lock(SupplierInvoice $invoice): SupplierInvoice
    {
        /** @var SupplierInvoice */
        return SupplierInvoice::query()->with('supplier')->lockForUpdate()->findOrFail($invoice->id);
    }
}
