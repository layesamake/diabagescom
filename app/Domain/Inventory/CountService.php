<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\Product;
use App\Models\StockOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Inventaire physique (docs/02) : comptage par scan ou recherche, calcul d'écart,
 * motif obligatoire pour tout écart, validation par une personne autorisée.
 *
 * La quantité théorique est figée à la validation, sous verrou des soldes : une
 * vente concurrente ne peut pas fausser l'écart calculé.
 */
final class CountService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    public function create(string $warehouseId, ?string $notes, User $actor): InventoryCount
    {
        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($warehouseId);

        return DB::transaction(function () use ($warehouse, $notes, $actor): InventoryCount {
            $count = new InventoryCount;
            $count->warehouse_id = $warehouse->id;
            $count->notes = $notes;
            $count->created_by = $actor->id;
            $count->save();

            $this->audit->record('stock.count_created', $count, after: ['warehouse' => $warehouse->code]);

            return $count;
        });
    }

    /**
     * Saisit (ou corrige) la quantité comptée d'un article ; brouillon uniquement.
     */
    public function setLine(InventoryCount $count, string $productId, string $counted, ?string $reason): InventoryCountLine
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($count, $productId, $counted, $reason): InventoryCountLine {
            $locked = $this->lock($count);
            $this->expectDraft($locked);
            $this->stockAccess->warehouse($locked->warehouse_id);

            $product = Product::query()->with('unit:id,decimals_allowed')->findOrFail($productId);
            $quantity = Quantity::of($counted);

            if ($quantity->isNegative()) {
                throw new InvalidStockOperation(__('inventory.errors.positive_quantity'));
            }

            if (! $product->unit->decimals_allowed && ! $quantity->isWhole()) {
                throw new InvalidStockOperation(__('inventory.errors.whole_quantity', ['product' => $product->name]));
            }

            /** @var InventoryCountLine $line */
            $line = $locked->lines()->firstOrNew(['product_id' => $product->id]);
            $line->counted_quantity = $quantity->value;
            $line->reason = blank($reason) ? null : trim((string) $reason);
            $line->save();

            return $line;
        });
    }

    public function removeLine(InventoryCount $count, InventoryCountLine $line): void
    {
        $this->access->assertCanWrite($this->context->tenant());

        DB::transaction(function () use ($count, $line): void {
            $locked = $this->lock($count);
            $this->stockAccess->warehouse($locked->warehouse_id);
            $this->expectDraft($locked);
            abort_unless($line->count_id === $locked->id, 404);
            $line->delete();
        });
    }

    public function validate(InventoryCount $count, User $actor, string $idempotencyKey): InventoryCount
    {
        return DB::transaction(function () use ($count, $actor, $idempotencyKey): InventoryCount {
            $locked = $this->lock($count);

            if ($locked->status === InventoryCount::VALIDATED && StockOperation::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $locked;
            }

            $this->expectDraft($locked);
            $this->stockAccess->warehouse($locked->warehouse_id);

            if ($locked->lines->isEmpty()) {
                throw new InvalidStockOperation(__('inventory.errors.no_lines'));
            }

            $tenantId = $this->context->tenantId();
            $productIds = $locked->lines->pluck('product_id')->sort()->values()->all();

            // Soldes verrouillés dans l'ordre (dépôt, article), comme dans StockLedger.
            $balances = DB::table('inventory_balances')
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $locked->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->pluck('quantity_on_hand', 'product_id');

            $movements = [];
            $missingReasons = [];

            foreach ($locked->lines as $line) {
                $theoretical = Quantity::of((string) ($balances[$line->product_id] ?? '0'));
                $variance = Quantity::of($line->counted_quantity)->sub($theoretical);

                $line->theoretical_quantity = $theoretical->value;
                $line->variance = $variance->value;
                $line->save();

                if (! $variance->isZero()) {
                    if (blank($line->reason)) {
                        $missingReasons[] = $line->product->name;
                    }

                    $movements[] = new MovementLine($locked->warehouse_id, $line->product_id, $variance);
                }
            }

            if ($missingReasons !== []) {
                throw new InvalidStockOperation(__('inventory.errors.count_reason_required', ['products' => implode(', ', $missingReasons)]));
            }

            $locked->number = $this->sequences->formatted('inventory_count:'.now()->year, 'INV-'.now()->year);

            if ($movements !== []) {
                $this->ledger->post('count', $idempotencyKey, $movements, $actor, source: $locked, reason: __('inventory.count_reason', ['number' => $locked->number]));
            }

            $locked->status = InventoryCount::VALIDATED;
            $locked->validated_by = $actor->id;
            $locked->validated_at = now();
            $locked->version++;
            $locked->save();

            $this->audit->record('stock.count_validated', $locked, after: ['number' => $locked->number, 'adjusted_lines' => count($movements)]);

            return $locked;
        });
    }

    public function cancel(InventoryCount $count, User $actor): InventoryCount
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($count, $actor): InventoryCount {
            $locked = $this->lock($count);
            $this->stockAccess->warehouse($locked->warehouse_id);
            $this->expectDraft($locked);

            $locked->status = InventoryCount::CANCELLED;
            $locked->cancelled_by = $actor->id;
            $locked->cancelled_at = now();
            $locked->version++;
            $locked->save();

            $this->audit->record('stock.count_cancelled', $locked);

            return $locked;
        });
    }

    private function lock(InventoryCount $count): InventoryCount
    {
        /** @var InventoryCount */
        return InventoryCount::query()->with('lines.product')->lockForUpdate()->findOrFail($count->id);
    }

    private function expectDraft(InventoryCount $count): void
    {
        if ($count->status !== InventoryCount::DRAFT) {
            throw new InvalidStockOperation(__('inventory.errors.count_closed'));
        }
    }
}
