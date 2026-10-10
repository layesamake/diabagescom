<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Caisse physique d'un dépôt.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $name
 * @property bool $active
 * @property-read Warehouse $warehouse
 * @property-read CashSession|null $openSession
 */
class CashRegister extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['warehouse_id', 'name', 'active'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<CashSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'register_id');
    }

    /**
     * @return HasOne<CashSession, $this>
     */
    public function openSession(): HasOne
    {
        return $this->hasOne(CashSession::class, 'register_id')->where('status', CashSession::OPEN);
    }
}
