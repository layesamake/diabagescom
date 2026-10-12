<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Boutique publique d'une entreprise cliente. */
class Storefront extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'slug', 'enabled', 'tagline', 'description', 'phone', 'email',
        'whatsapp', 'address', 'opening_hours', 'accepts_orders', 'delivery_enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'accepts_orders' => 'boolean',
            'delivery_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<StorefrontProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(StorefrontProduct::class);
    }

    /** @return HasMany<StorefrontOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(StorefrontOrder::class);
    }
}
