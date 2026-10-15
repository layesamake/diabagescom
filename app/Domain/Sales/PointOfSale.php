<?php

namespace App\Domain\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Cash\InvalidCashOperation;
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
            return $existing;
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
            return $this->existing($idempotencyKey) ?? throw $e;
        }
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
