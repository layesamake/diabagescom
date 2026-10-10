<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Opération de stock (append-only) regroupant un ou plusieurs mouvements.
 * La clé d'idempotence empêche qu'une opération rejouée soit appliquée deux fois.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $idempotency_key
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $reason
 * @property bool $negative_stock_allowed
 * @property string $created_by
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class StockOperation extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'negative_stock_allowed' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock operations are append-only.'));
        static::deleting(fn () => throw new LogicException('Stock operations are append-only.'));
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'operation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
