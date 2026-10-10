<?php

namespace App\Domain\Inventory;

use App\Models\StockOperation;
use App\Models\User;

/**
 * Stock initial et ajustements manuels (docs/09 : motif obligatoire).
 * Un ajustement ne peut jamais rendre le stock négatif.
 */
final class AdjustmentService
{
    public function __construct(
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * Stock d'ouverture : entrées valorisées au coût saisi.
     *
     * @param  list<array{product_id: string, quantity: string, unit_cost: int}>  $lines
     */
    public function initial(string $warehouseId, array $lines, ?string $reason, User $actor, string $idempotencyKey): StockOperation
    {
        $warehouse = $this->stockAccess->warehouse($warehouseId);

        $movements = array_map(function (array $line) use ($warehouse): MovementLine {
            $quantity = Quantity::of($line['quantity']);

            if (! $quantity->isPositive()) {
                throw new InvalidStockOperation(__('inventory.errors.positive_quantity'));
            }

            return new MovementLine($warehouse->id, $line['product_id'], $quantity, $line['unit_cost']);
        }, $lines);

        return $this->ledger->post('initial', $idempotencyKey, $movements, $actor, reason: $reason);
    }

    /**
     * @param  list<array{product_id: string, quantity: string, unit_cost?: int|null}>  $lines  quantité signée (+ entrée, − sortie)
     */
    public function adjust(string $warehouseId, array $lines, string $reason, User $actor, string $idempotencyKey): StockOperation
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidStockOperation(__('inventory.errors.reason_required'));
        }

        $warehouse = $this->stockAccess->warehouse($warehouseId);

        $movements = array_map(fn (array $line) => new MovementLine(
            $warehouse->id,
            $line['product_id'],
            Quantity::of($line['quantity']),
            $line['unit_cost'] ?? null,
        ), $lines);

        return $this->ledger->post('adjustment', $idempotencyKey, $movements, $actor, reason: trim($reason));
    }
}
