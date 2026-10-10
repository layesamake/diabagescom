<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfert entre dépôts en deux temps : brouillon → en transit (expédié) → reçu.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property string $source_warehouse_id
 * @property string $destination_warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property string|null $receipt_note
 * @property string $created_by
 * @property string|null $shipped_by
 * @property CarbonImmutable|null $shipped_at
 * @property string|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Warehouse $source
 * @property-read Warehouse $destination
 */
class StockTransfer extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string IN_TRANSIT = 'in_transit';

    public const string RECEIVED = 'received';

    public const string CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }
}
