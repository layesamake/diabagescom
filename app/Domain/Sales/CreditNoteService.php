<?php

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\StockLedger;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Avoir sur facture validée (docs/05 « flux de correction ») : la facture n'est
 * jamais modifiée ; l'avoir la référence, réduit son reste à payer et, pour les
 * articles retournés, les remet en stock au coût de la vente d'origine.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
        private readonly SalesDocumentService $documents,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<array{line_id: string, quantity: string, restock: bool}>  $lines
     */
    public function create(SalesDocument $invoice, array $lines, string $reason, User $actor, string $idempotencyKey): SalesDocument
    {
        $this->access->assertCanWrite($this->context->tenant());

        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidSalesOperation(__('sales.errors.reason_required'));
        }

        try {
            return DB::transaction(function () use ($invoice, $lines, $reason, $actor, $idempotencyKey): SalesDocument {
                $existing = $this->existing($idempotencyKey, $invoice);

                if ($existing !== null) {
                    return $existing;
                }

                $invoice = $this->documents->lock($invoice);

                if ($invoice->type !== SalesDocument::INVOICE || $invoice->status !== SalesDocument::VALIDATED) {
                    throw new InvalidSalesOperation(__('sales.errors.credit_invoice_only'));
                }

                $this->stockAccess->warehouse($invoice->warehouse_id);
                $sourceLines = $invoice->lines()->get()->keyBy('id');
                $credited = $this->alreadyCredited($invoice);

                $credit = new SalesDocument;
                $credit->type = SalesDocument::CREDIT_NOTE;
                $credit->warehouse_id = $invoice->warehouse_id;
                $credit->customer_id = $invoice->customer_id;
                $credit->source_document_id = $invoice->id;
                $credit->reason = trim($reason);
                $credit->idempotency_key = $idempotencyKey;
                $credit->created_by = $actor->id;
                $credit->save();

                $restock = [];
                $position = 0;

                foreach ($lines as $input) {
                    /** @var SalesDocumentLine|null $source */
                    $source = $sourceLines[$input['line_id']] ?? null;

                    if ($source === null) {
                        throw new InvalidSalesOperation(__('sales.errors.unknown_line'));
                    }

                    try {
                        $quantity = Quantity::of($input['quantity']);
                    } catch (InvalidArgumentException $e) {
                        throw new InvalidSalesOperation($e->getMessage());
                    }

                    if ($quantity->isZero()) {
                        continue;
                    }

                    $sold = Quantity::of($source->quantity);
                    $previous = $credited[$source->id] ?? ['quantity' => Quantity::zero(), 'total' => 0];
                    $remaining = $sold->sub($previous['quantity']);

                    if ($quantity->isNegative() || $quantity->compare($remaining) > 0) {
                        throw new InvalidSalesOperation(__('sales.errors.credit_exceeds', ['product' => $source->label, 'max' => $remaining->format()]));
                    }

                    // Le dernier avoir solde exactement la ligne ; sinon prorata arrondi.
                    $gross = $quantity->times($source->unit_price);
                    $total = $quantity->compare($remaining) === 0
                        ? $source->line_total - $previous['total']
                        : Pricing::prorate($source->line_total, $quantity, $sold);
                    $total = max(0, min($total, $gross));
                    $exclTax = Pricing::exclusiveFromInclusive($total, $source->tax_rate_basis_points);
                    $returnsToStock = $input['restock'] && $source->product_type !== 'service';

                    // Une même ligne citée deux fois dans la requête consomme le même reste.
                    $credited[$source->id] = ['quantity' => $previous['quantity']->add($quantity), 'total' => $previous['total'] + $total];

                    $line = new SalesDocumentLine;
                    $line->forceFill([
                        'document_id' => $credit->id,
                        'position' => ++$position,
                        'product_id' => $source->product_id,
                        'sku' => $source->sku,
                        'label' => $source->label,
                        'unit_code' => $source->unit_code,
                        'product_type' => $source->product_type,
                        'quantity' => $quantity->value,
                        'unit_price' => $source->unit_price,
                        'discount_amount' => $gross - $total,
                        'tax_rate_basis_points' => $source->tax_rate_basis_points,
                        'line_total' => $total,
                        'line_excl_tax' => $exclTax,
                        'line_tax' => $total - $exclTax,
                        'unit_cost' => $source->unit_cost,
                        'source_line_id' => $source->id,
                        'restock' => $returnsToStock,
                    ])->save();

                    if ($returnsToStock) {
                        $restock[] = new MovementLine($invoice->warehouse_id, $source->product_id, $quantity, $source->unit_cost);
                    }
                }

                if ($position === 0) {
                    throw new InvalidSalesOperation(__('sales.errors.no_lines'));
                }

                $this->documents->refreshTotals($credit);

                if ($restock !== []) {
                    $this->ledger->post('customer_return', "sales_document:{$credit->id}", $restock, $actor, source: $credit, reason: trim($reason));
                }

                $credit->customer_snapshot = $invoice->customer_snapshot;
                $credit->issue_date = now()->toImmutable()->startOfDay();
                $credit->number = $this->sequences->document('sales:credit_note', SalesDocument::PREFIXES[SalesDocument::CREDIT_NOTE]);
                $credit->status = SalesDocument::VALIDATED;
                $credit->validated_by = $actor->id;
                $credit->validated_at = now();
                $credit->save();

                $invoice->amount_credited += $credit->total;
                $this->documents->refreshPaymentStatus($invoice);
                $invoice->version++;
                $invoice->save();

                $this->audit->record('sales.credit_note_validated', $credit,
                    after: ['number' => $credit->number, 'invoice' => $invoice->number, 'total' => $credit->total, 'restocked' => count($restock)],
                    metadata: ['reason' => trim($reason)], actor: $actor);

                return $credit;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($idempotencyKey, $invoice) ?? throw $e;
        }
    }

    /**
     * Avoir déjà créé avec cette clé. Une clé réutilisée pour une autre facture est
     * une erreur du client : on la refuse au lieu de renvoyer l'avoir d'un autre document.
     */
    private function existing(string $idempotencyKey, SalesDocument $invoice): ?SalesDocument
    {
        $existing = SalesDocument::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null && ($existing->type !== SalesDocument::CREDIT_NOTE || $existing->source_document_id !== $invoice->id)) {
            throw new InvalidSalesOperation(__('sales.errors.idempotency_conflict'));
        }

        return $existing;
    }

    /**
     * Quantités et montants déjà crédités par ligne de facture.
     *
     * @return array<string, array{quantity: Quantity, total: int}>
     */
    public function alreadyCredited(SalesDocument $invoice): array
    {
        $rows = SalesDocumentLine::query()
            ->whereIn('document_id', SalesDocument::query()->select('id')
                ->where('source_document_id', $invoice->id)
                ->where('type', SalesDocument::CREDIT_NOTE)
                ->where('status', SalesDocument::VALIDATED))
            ->selectRaw('source_line_id, SUM(quantity) AS quantity, SUM(line_total) AS total')
            ->groupBy('source_line_id')
            ->get();

        $credited = [];

        foreach ($rows as $row) {
            $credited[(string) $row->getAttribute('source_line_id')] = [
                'quantity' => Quantity::of((string) $row->getAttribute('quantity')),
                'total' => (int) $row->getAttribute('total'),
            ];
        }

        return $credited;
    }
}
