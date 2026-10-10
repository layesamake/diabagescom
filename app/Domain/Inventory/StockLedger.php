<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Seul point d'écriture du stock (EPIC-04).
 *
 * Chaque appel crée une opération et ses mouvements immuables, et met à jour les
 * soldes dans la même transaction :
 * - idempotent : une clé déjà utilisée renvoie l'opération existante sans rien rejouer ;
 * - concurrent : les lignes de solde sont verrouillées (FOR UPDATE) dans un ordre
 *   fixe (dépôt, article) pour éviter pertes de mise à jour et interblocages ;
 * - stock négatif refusé, sauf autorisation explicite et motivée ;
 * - valorisation au coût moyen pondéré (docs/05), coûts en XOF entiers.
 */
final class StockLedger
{
    /** Types d'opérations et type de mouvement selon le sens. */
    public const array OPERATIONS = [
        'initial' => ['in' => 'initial', 'out' => null],
        'receipt' => ['in' => 'receipt', 'out' => null],
        'sale' => ['in' => null, 'out' => 'sale'],
        'customer_return' => ['in' => 'customer_return', 'out' => null],
        'supplier_return' => ['in' => null, 'out' => 'supplier_return'],
        'transfer_shipment' => ['in' => null, 'out' => 'transfer_out'],
        'transfer_receipt' => ['in' => 'transfer_in', 'out' => null],
        'adjustment' => ['in' => 'adjustment_in', 'out' => 'adjustment_out'],
        'count' => ['in' => 'count_adjustment', 'out' => 'count_adjustment'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<MovementLine>  $lines
     */
    public function post(
        string $operationType,
        string $idempotencyKey,
        array $lines,
        User $actor,
        ?Model $source = null,
        ?string $reason = null,
        bool $allowNegative = false,
        ?CarbonImmutable $occurredAt = null,
    ): StockOperation {
        if (! array_key_exists($operationType, self::OPERATIONS)) {
            throw new InvalidArgumentException("Unknown stock operation [{$operationType}].");
        }

        if ($lines === []) {
            throw new InvalidStockOperation(__('inventory.errors.no_lines'));
        }

        if ($allowNegative && blank($reason)) {
            throw new InvalidStockOperation(__('inventory.errors.negative_reason_required'));
        }

        $this->access->assertCanWrite($this->context->tenant());

        $existing = $this->find($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->apply($operationType, $idempotencyKey, $lines, $actor, $source, $reason, $allowNegative, $occurredAt ?? CarbonImmutable::now()));
        } catch (UniqueConstraintViolationException $e) {
            // Rejeu concurrent de la même opération : l'autre transaction a gagné.
            return $this->find($idempotencyKey) ?? throw $e;
        }
    }

    /**
     * @param  list<MovementLine>  $lines
     */
    private function apply(
        string $operationType,
        string $idempotencyKey,
        array $lines,
        User $actor,
        ?Model $source,
        ?string $reason,
        bool $allowNegative,
        CarbonImmutable $occurredAt,
    ): StockOperation {
        $tenantId = $this->context->tenantId();
        $products = Product::query()->with('unit:id,decimals_allowed')
            ->whereIn('id', array_unique(array_map(fn (MovementLine $l) => $l->productId, $lines)))
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $product = $products[$line->productId] ?? throw new InvalidStockOperation(__('inventory.errors.unknown_product'));

            if ($line->delta->isZero()) {
                throw new InvalidStockOperation(__('inventory.errors.zero_quantity', ['product' => $product->name]));
            }

            if (! $product->unit->decimals_allowed && ! $line->delta->isWhole()) {
                throw new InvalidStockOperation(__('inventory.errors.whole_quantity', ['product' => $product->name]));
            }

            if ($product->type === 'service') {
                throw new InvalidStockOperation(__('inventory.errors.service', ['product' => $product->name]));
            }

            $direction = $line->delta->isPositive() ? 'in' : 'out';
            if (self::OPERATIONS[$operationType][$direction] === null) {
                throw new InvalidStockOperation(__('inventory.errors.direction'));
            }
        }

        $operation = new StockOperation;
        $operation->type = $operationType;
        $operation->idempotency_key = $idempotencyKey;
        $operation->source_type = $source !== null ? class_basename($source) : null;
        $operation->source_id = $source?->getKey();
        $operation->reason = $reason;
        $operation->negative_stock_allowed = $allowNegative;
        $operation->created_by = $actor->id;
        $operation->occurred_at = $occurredAt;
        $operation->save();

        // Verrouillage dans un ordre stable (dépôt, article) : pas d'interblocage entre opérations concurrentes.
        $keys = collect($lines)->map(fn (MovementLine $l) => [$l->warehouseId, $l->productId])->unique(fn ($k) => implode('|', $k))->sortBy(fn ($k) => implode('|', $k))->values();
        $balances = [];

        foreach ($keys as [$warehouseId, $productId]) {
            DB::table('inventory_balances')->insertOrIgnore([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'product_id' => $productId,
                'quantity_on_hand' => '0', 'quantity_reserved' => '0', 'average_cost' => 0, 'updated_at' => $occurredAt,
            ]);

            $row = DB::table('inventory_balances')
                ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->lockForUpdate()->first(['quantity_on_hand', 'average_cost']);

            $balances["{$warehouseId}|{$productId}"] = ['quantity' => Quantity::of((string) $row?->quantity_on_hand), 'average_cost' => (int) $row?->average_cost];
        }

        $summary = [];

        foreach ($lines as $line) {
            $key = "{$line->warehouseId}|{$line->productId}";
            $product = $products[$line->productId];
            $before = $balances[$key];
            $after = $before['quantity']->add($line->delta);

            if ($line->delta->isNegative() && $after->isNegative() && ! $allowNegative) {
                throw new InsufficientStock($product->name, $before['quantity']->format(), $line->delta->negate()->format());
            }

            if ($line->delta->isPositive()) {
                $unitCost = $line->unitCost ?? ($before['average_cost'] > 0 ? $before['average_cost'] : $product->standard_cost);
                $averageCost = $this->weightedAverage($before['quantity'], $before['average_cost'], $line->delta, $unitCost);
            } else {
                $unitCost = $before['average_cost'] > 0 ? $before['average_cost'] : $product->standard_cost;
                $averageCost = $before['average_cost'];
            }

            $movement = new InventoryMovement;
            $movement->forceFill([
                'id' => (string) Str::uuid7(),
                'operation_id' => $operation->id,
                'warehouse_id' => $line->warehouseId,
                'product_id' => $line->productId,
                'type' => self::OPERATIONS[$operationType][$line->delta->isPositive() ? 'in' : 'out'],
                'quantity_delta' => $line->delta->value,
                'unit_cost' => $unitCost,
                'balance_after' => $after->value,
                'average_cost_after' => $averageCost,
                'occurred_at' => $occurredAt,
            ])->save();

            DB::table('inventory_balances')
                ->where('tenant_id', $tenantId)->where('warehouse_id', $line->warehouseId)->where('product_id', $line->productId)
                ->update([
                    'quantity_on_hand' => $after->value,
                    'average_cost' => $averageCost,
                    'last_movement_id' => $movement->id,
                    'updated_at' => $occurredAt,
                ]);

            $balances[$key] = ['quantity' => $after, 'average_cost' => $averageCost];
            $summary[] = ['product' => $product->sku, 'delta' => $line->delta->value, 'after' => $after->value];
        }

        $this->audit->record("stock.{$operationType}", $operation,
            after: ['lines' => $summary],
            metadata: array_filter(['reason' => $reason, 'negative_stock_allowed' => $allowNegative ?: null]),
            actor: $actor);

        return $operation;
    }

    /**
     * Coût moyen pondéré arrondi au franc. Si le stock était nul ou négatif,
     * le coût de l'entrée devient le coût moyen.
     */
    private function weightedAverage(Quantity $quantity, int $averageCost, Quantity $incoming, int $incomingCost): int
    {
        if (! $quantity->isPositive()) {
            return $incomingCost;
        }

        $totalValue = bcadd(bcmul($quantity->value, (string) $averageCost, 3), bcmul($incoming->value, (string) $incomingCost, 3), 3);
        $totalQuantity = bcadd($quantity->value, $incoming->value, 3);

        return (int) bcadd(bcdiv($totalValue, $totalQuantity, 3), '0.5', 0);
    }

    private function find(string $idempotencyKey): ?StockOperation
    {
        return StockOperation::query()->where('idempotency_key', $idempotencyKey)->first();
    }
}
