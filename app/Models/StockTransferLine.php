<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $transfer_id
 * @property string $product_id
 * @property string $quantity
 * @property string|null $quantity_received
 * @property-read Product $product
 */
class StockTransferLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['product_id', 'quantity'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
