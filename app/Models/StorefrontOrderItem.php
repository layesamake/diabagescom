<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontOrderItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'order_id', 'product_id', 'product_name', 'sku', 'quantity',
        'unit_price', 'subtotal',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'integer', 'subtotal' => 'integer'];
    }

    /** @return BelongsTo<StorefrontOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
