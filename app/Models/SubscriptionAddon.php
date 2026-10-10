<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Option à la carte (utilisateurs, dépôts ou stockage supplémentaires).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string $addon_code
 * @property int $quantity
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 */
class SubscriptionAddon extends Model
{
    use BelongsToTenant, HasUuids;

    public const string EXTRA_USER = 'extra_user';

    public const string EXTRA_WAREHOUSE = 'extra_warehouse';

    public const string EXTRA_STORAGE_GB = 'extra_storage_gb';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['subscription_id', 'addon_code', 'quantity', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
