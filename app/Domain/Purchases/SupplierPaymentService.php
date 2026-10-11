<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Cash\CashService;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Payment;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Règlement d'une facture fournisseur (total ou partiel, jamais au-delà du reste
 * dû). Un règlement en espèces sort d'une session de caisse ouverte et ne peut
 * pas dépasser les espèces présentes.
 */
final class SupplierPaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly SupplierInvoiceService $invoices,
        private readonly CashService $cash,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    public function pay(SupplierInvoice $invoice, string $method, int $amount, ?string $sessionId, ?string $reference, User $actor, string $idempotencyKey): Payment
    {
        $this->access->assertCanWrite($this->context->tenant());

        if ($amount <= 0 || ! in_array($method, Payment::METHODS, true)) {
            throw new InvalidPurchaseOperation(__('purchases.errors.invalid_payment'));
        }

        $existing = $this->existing($idempotencyKey, $invoice);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($invoice, $method, $amount, $sessionId, $reference, $actor, $idempotencyKey): Payment {
                $invoice = $this->invoices->lock($invoice);
                $this->stockAccess->warehouse($invoice->warehouse_id);

                if ($invoice->status !== SupplierInvoice::VALIDATED) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.pay_validated_only'));
                }

                if ($amount > $invoice->balanceDue()) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.overpayment', ['max' => number_format(max(0, $invoice->balanceDue()), 0, ',', ' ')]));
                }

                $session = $method === 'cash'
                    ? $this->cash->lockOpenSession($sessionId ?? throw new InvalidPurchaseOperation(__('purchases.errors.cash_needs_session')), $invoice->warehouse_id)
                    : null;

                $payment = new Payment;
                $payment->forceFill([
                    'number' => $this->sequences->document('payment:supplier', 'PF'),
                    'direction' => 'out',
                    'kind' => 'supplier_payment',
                    'method' => $method,
                    'amount' => $amount,
                    'warehouse_id' => $invoice->warehouse_id,
                    'supplier_id' => $invoice->supplier_id,
                    'supplier_invoice_id' => $invoice->id,
                    'cash_session_id' => $session?->id,
                    'reference' => filled($reference) ? trim((string) $reference) : null,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actor->id,
                    'paid_at' => now(),
                ])->save();

                if ($session !== null) {
                    $this->cash->recordMovement($session, 'supplier_payment', -$amount, $actor, "{$idempotencyKey}:cash", $payment, label: $invoice->number);
                }

                $invoice->amount_paid += $amount;
                SupplierInvoiceService::refreshPaymentStatus($invoice);
                $invoice->version++;
                $invoice->save();

                $this->audit->record('purchase.payment', $invoice, after: ['payment' => $payment->number, 'amount' => $amount, 'method' => $method], actor: $actor);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($idempotencyKey, $invoice) ?? throw $e;
        }
    }

    /**
     * Règlement déjà enregistré avec cette clé ; refusé s'il concerne une autre facture.
     */
    private function existing(string $idempotencyKey, SupplierInvoice $invoice): ?Payment
    {
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null && ($existing->supplier_invoice_id !== $invoice->id || $existing->kind !== 'supplier_payment')) {
            throw new InvalidPurchaseOperation(__('purchases.errors.idempotency_conflict'));
        }

        return $existing;
    }
}
