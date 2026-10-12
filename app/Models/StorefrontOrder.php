<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontOrder extends Model
{
    use HasUuids;

    public const array STATUSES = ['new', 'confirmed', 'preparing', 'ready', 'completed', 'rejected', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'storefront_id', 'number', 'status', 'customer_name', 'customer_phone',
        'customer_email', 'delivery_address', 'fulfillment', 'notes', 'total', 'currency',
        'confirmed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    /** @return HasMany<StorefrontOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StorefrontOrderItem::class, 'order_id');
    }
}
