<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontProduct extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'storefront_id', 'product_id', 'visible', 'public_name',
        'public_description', 'public_price', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'public_price' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
