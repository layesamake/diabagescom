<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entreprise cliente (tenant). N'est pas elle-même soumise au scope tenant.
 *
 * @property string $id
 * @property string $legal_name
 * @property string $display_name
 * @property string $country_code
 * @property string $currency_code
 * @property string $timezone
 * @property string $locale
 * @property string $status
 * @property bool $accounting_activated
 * @property bool $online_store_activated
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    public const string STATUS_ACTIVE = 'active';

    protected $attributes = ['status' => 'active', 'country_code' => 'SN', 'currency_code' => 'XOF', 'timezone' => 'Africa/Dakar', 'locale' => 'fr'];

    protected $fillable = ['legal_name', 'display_name', 'country_code', 'timezone', 'locale'];

    protected function casts(): array
    {
        return [
            'accounting_activated' => 'boolean',
            'online_store_activated' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
