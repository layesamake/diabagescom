<?php

namespace App\Domain\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Cash\InvalidCashOperation;
use App\Domain\Inventory\Quantity;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Vente comptoir (docs/07) : panier → paiement → validation → reçu, en une seule
 * transaction tout-ou-rien. La clé d'idempotence fournie par l'écran garantit
 * qu'un double clic ou un renvoi après coupure ne crée jamais deux ventes.
 */
final class PointOfSale
{
    public function __construct(
        private readonly SalesDocumentService $documents,
        private readonly PaymentService $payments,
        private readonly CashService $cash,
    ) {}

    /**
     * @param  array{warehouse_id: string, customer_id?: string|null, cash_session_id: string, negative_stock_reason?: string|null, lines: list<array{product_id: string, quantity: string, unit_price?: int|null, discount?: int|null}>, tenders: list<array{method: string, amount: int, reference?: string|null}>}  $data
     */
    public function checkout(array $data, User $actor, string $idempotencyKey): SalesDocument
    {
        $existing = $this->existing($idempotencyKey);

        if ($existing !== null) {
            return $this->sameOperation($existing, $data);
        }

        if (blank($data['cash_session_id'])) {
            throw new InvalidCashOperation(__('cash.errors.session_required'));
        }

        try {
            return DB::transaction(function () use ($data, $actor, $idempotencyKey): SalesDocument {
                $tenders = array_values(array_filter($data['tenders'], fn (array $t) => (int) $t['amount'] > 0));
                $paid = array_sum(array_map(fn (array $t) => (int) $t['amount'], $tenders));

                $invoice = $this->documents->saveDraft(null, SalesDocument::INVOICE, [
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'channel' => 'pos',
                    'idempotency_key' => $idempotencyKey,
                    'lines' => $data['lines'],
                ], $actor);

                $invoice = $this->documents->validate($invoice, $actor, $data['negative_stock_reason'] ?? null, $paid);

                if ($tenders !== []) {
                    $this->payments->receive($invoice, $tenders, $data['cash_session_id'], $actor, "pos:{$idempotencyKey}");
                } else {
                    // Vente entièrement à crédit : la caisse doit tout de même être ouverte.
                    $this->cash->lockOpenSession($data['cash_session_id'], $invoice->warehouse_id, $actor);
                }

                return $invoice->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->existing($idempotencyKey) ?? throw $e;

            return $this->sameOperation($existing, $data);
        }
    }

    /**
     * Un rejeu ne renvoie la vente enregistrée que s'il décrit la même opération :
     * même dépôt, même client, mêmes articles et quantités, mêmes règlements. Une clé
     * réutilisée avec un autre contenu est refusée, jamais confirmée à tort.
     *
     * @param  array{warehouse_id: string, customer_id?: string|null, cash_session_id: string, lines: list<array{product_id: string, quantity: string}>, tenders: list<array{method: string, amount: int}>}  $data
     */
    private function sameOperation(SalesDocument $existing, array $data): SalesDocument
    {
        $quantities = fn (iterable $rows): array => collect($rows)->reduce(function (array $carry, $row): array {
            $productId = (string) (is_array($row) ? $row['product_id'] : $row->product_id);
            $quantity = Quantity::of((string) (is_array($row) ? $row['quantity'] : $row->quantity));
            $carry[$productId] = isset($carry[$productId]) ? $carry[$productId]->add($quantity) : $quantity;

            return $carry;
        }, []);
        $normalize = fn (array $map): array => collect($map)->map(fn (Quantity $q) => $q->value)->sortKeys()->all();

        $paid = [];

        foreach ($data['tenders'] as $tender) {
            if ((int) $tender['amount'] > 0) {
                $paid[$tender['method']] = ($paid[$tender['method']] ?? 0) + (int) $tender['amount'];
            }
        }

        $payments = Payment::query()->where('sales_document_id', $existing->id)->where('kind', 'customer_payment')->get();
        $recorded = [];

        foreach ($payments as $payment) {
            $recorded[$payment->method] = ($recorded[$payment->method] ?? 0) + $payment->amount;
        }

        ksort($paid);
        ksort($recorded);

        $sameSession = $payments->isEmpty() || $payments->every(fn (Payment $p) => $p->cash_session_id === ($data['cash_session_id'] ?? null));

        if ($existing->warehouse_id !== $data['warehouse_id']
            || ($existing->customer_id ?? null) !== ($data['customer_id'] ?? null)
            || $normalize($quantities($existing->lines)) !== $normalize($quantities($data['lines']))
            || $paid !== $recorded
            || ! $sameSession) {
            throw new InvalidSalesOperation(__('sales.errors.idempotency_conflict'));
        }

        return $existing;
    }

    /**
     * Vente comptoir déjà enregistrée avec cette clé ; une clé portée par un autre
     * type de document (avoir, brouillon de bureau) est refusée.
     */
    private function existing(string $idempotencyKey): ?SalesDocument
    {
        $existing = SalesDocument::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null && ($existing->type !== SalesDocument::INVOICE || $existing->channel !== 'pos')) {
            throw new InvalidSalesOperation(__('sales.errors.idempotency_conflict'));
        }

        return $existing;
    }
}
