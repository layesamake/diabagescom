<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventaire physique d'un dépôt : saisie des quantités comptées, puis
 * validation (écarts motivés, mouvements count_adjustment).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property string $warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property string $created_by
 * @property string|null $validated_by
 * @property CarbonImmutable|null $validated_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Warehouse $warehouse
 */
class InventoryCount extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    public const string CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<InventoryCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class, 'count_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
