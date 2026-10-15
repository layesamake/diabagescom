<?php

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLog;
use App\Domain\Cash\CashService;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Encaissements et remboursements clients (docs/02 §5) :
 * - paiement simple, partiel ou multi-mode, jamais au-delà du reste à payer
 *   (la monnaie rendue n'est pas un paiement) ;
 * - les espèces passent obligatoirement par une session de caisse ouverte ;
 * - idempotent : une clé déjà utilisée renvoie les paiements existants ;
 * - remboursement uniquement d'un trop-perçu (après avoir), motif obligatoire.
 */
final class PaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly SalesDocumentService $documents,
        private readonly CashService $cash,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<array{method: string, amount: int, reference?: string|null}>  $tenders
     * @return list<Payment>
     */
    public function receive(SalesDocument $invoice, array $tenders, ?string $sessionId, User $actor, string $idempotencyKey, ?string $note = null): array
    {
        $this->access->assertCanWrite($this->context->tenant());

        $tenders = array_values(array_filter($tenders, fn (array $t) => (int) $t['amount'] !== 0));

        if ($tenders === []) {
            throw new InvalidSalesOperation(__('sales.errors.no_payment'));
        }

        foreach ($tenders as $tender) {
            if (! in_array($tender['method'], Payment::METHODS, true)) {
                throw new InvalidSalesOperation(__('sales.errors.payment_method'));
            }

            if ((int) $tender['amount'] <= 0) {
                throw new InvalidSalesOperation(__('sales.errors.payment_positive'));
            }
        }

        try {
            return DB::transaction(function () use ($invoice, $tenders, $sessionId, $actor, $idempotencyKey, $note): array {
                $existing = $this->existing($idempotencyKey, $invoice, 'customer_payment');

                if ($existing !== []) {
                    return $existing;
                }

                $invoice = $this->documents->lock($invoice);
                $this->expectInvoice($invoice);
                $this->stockAccess->warehouse($invoice->warehouse_id);

                $total = array_sum(array_map(fn (array $t) => (int) $t['amount'], $tenders));

                if ($total > $invoice->balanceDue()) {
                    throw new InvalidSalesOperation(__('sales.errors.overpayment'));
                }

                $needsSession = $sessionId !== null || in_array('cash', array_column($tenders, 'method'), true);
                $session = $needsSession
                    ? $this->cash->lockOpenSession($sessionId ?? throw new InvalidSalesOperation(__('sales.errors.cash_needs_session')), $invoice->warehouse_id, $actor)
                    : null;

                $payments = [];

                foreach ($tenders as $index => $tender) {
                    $payments[] = $this->write($invoice, 'in', 'customer_payment', $tender['method'], (int) $tender['amount'],
                        $session, $actor, "{$idempotencyKey}:{$index}", $tender['reference'] ?? null, $note);
                }

                $invoice->amount_paid += $total;
                $this->documents->refreshPaymentStatus($invoice);
                $invoice->version++;
                $invoice->save();

                $this->audit->record('sales.payment_received', $invoice,
                    after: ['amount' => $total, 'payments' => array_map(fn (Payment $p) => $p->number, $payments), 'payment_status' => $invoice->payment_status],
                    actor: $actor);

                return $payments;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($idempotencyKey, $invoice, 'customer_payment') ?: throw $e;
        }
    }

    public function refund(SalesDocument $invoice, int $amount, string $method, ?string $sessionId, string $reason, User $actor, string $idempotencyKey): Payment
    {
        $this->access->assertCanWrite($this->context->tenant());

        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidSalesOperation(__('sales.errors.reason_required'));
        }

        if ($amount <= 0 || ! in_array($method, Payment::METHODS, true)) {
            throw new InvalidSalesOperation(__('sales.errors.payment_positive'));
        }

        try {
            return DB::transaction(function () use ($invoice, $amount, $method, $sessionId, $reason, $actor, $idempotencyKey): Payment {
                $existing = $this->existing($idempotencyKey, $invoice, 'customer_refund');

                if ($existing !== []) {
                    return $existing[0];
                }

                $invoice = $this->documents->lock($invoice);
                $this->expectInvoice($invoice);
                $this->stockAccess->warehouse($invoice->warehouse_id);

                if ($amount > -$invoice->balanceDue()) {
                    throw new InvalidSalesOperation(__('sales.errors.refund_exceeds', ['max' => number_format(max(0, -$invoice->balanceDue()), 0, ',', ' ')]));
                }

                $session = $method === 'cash' || $sessionId !== null
                    ? $this->cash->lockOpenSession($sessionId ?? throw new InvalidSalesOperation(__('sales.errors.cash_needs_session')), $invoice->warehouse_id, $actor)
                    : null;

                $payment = $this->write($invoice, 'out', 'customer_refund', $method, $amount, $session, $actor, "{$idempotencyKey}:0", null, trim($reason));

                $invoice->amount_paid -= $amount;
                $this->documents->refreshPaymentStatus($invoice);
                $invoice->version++;
                $invoice->save();

                $this->audit->record('sales.refund', $invoice, after: ['amount' => $amount, 'payment' => $payment->number], metadata: ['reason' => trim($reason)], actor: $actor);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($idempotencyKey, $invoice, 'customer_refund')[0] ?? throw $e;
        }
    }

    private function write(SalesDocument $invoice, string $direction, string $kind, string $method, int $amount, ?CashSession $session, User $actor, string $key, ?string $reference, ?string $note): Payment
    {
        $payment = new Payment;
        $payment->forceFill([
            'number' => $this->sequences->document("payment:{$direction}", $direction === 'in' ? 'RC' : 'RB'),
            'direction' => $direction,
            'kind' => $kind,
            'method' => $method,
            'amount' => $amount,
            'warehouse_id' => $invoice->warehouse_id,
            'customer_id' => $invoice->customer_id,
            'sales_document_id' => $invoice->id,
            'cash_session_id' => $session?->id,
            'reference' => filled($reference) ? trim((string) $reference) : null,
            'note' => $note,
            'idempotency_key' => $key,
            'created_by' => $actor->id,
            'paid_at' => now(),
        ])->save();

        if ($method === 'cash' && $session !== null) {
            $this->cash->recordMovement($session, $kind, $direction === 'in' ? $amount : -$amount, $actor, "{$key}:cash", $payment,
                label: $invoice->number);
        }

        return $payment;
    }

    /**
     * Paiements déjà enregistrés avec cette clé. Une clé réutilisée pour une autre
     * facture ou une autre opération est refusée : renvoyer ces paiements ferait
     * croire au client que sa facture est réglée.
     *
     * @return list<Payment>
     */
    private function existing(string $idempotencyKey, SalesDocument $invoice, string $kind): array
    {
        $payments = array_values(Payment::query()
            ->where('idempotency_key', 'like', addcslashes($idempotencyKey, '%_\\').':%')
            ->orderBy('idempotency_key')->get()->all());

        foreach ($payments as $payment) {
            if ($payment->sales_document_id !== $invoice->id || $payment->kind !== $kind) {
                throw new InvalidSalesOperation(__('sales.errors.idempotency_conflict'));
            }
        }

        return $payments;
    }

    private function expectInvoice(SalesDocument $invoice): void
    {
        if ($invoice->type !== SalesDocument::INVOICE || $invoice->status !== SalesDocument::VALIDATED) {
            throw new InvalidSalesOperation(__('sales.errors.payment_invoice_only'));
        }
    }
}
