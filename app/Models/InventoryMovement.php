<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Mouvement de stock immuable. Écrit uniquement par App\Domain\Inventory\StockLedger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $operation_id
 * @property string $warehouse_id
 * @property string $product_id
 * @property string $type
 * @property string $quantity_delta
 * @property int $unit_cost
 * @property string $balance_after
 * @property int $average_cost_after
 * @property CarbonImmutable $occurred_at
 * @property-read StockOperation $operation
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 */
class InventoryMovement extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const array TYPES = [
        'initial', 'receipt', 'sale', 'customer_return', 'supplier_return',
        'transfer_out', 'transfer_in', 'adjustment_in', 'adjustment_out', 'count_adjustment',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'average_cost_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Inventory movements are append-only.'));
    }

    /**
     * @return BelongsTo<StockOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'operation_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
