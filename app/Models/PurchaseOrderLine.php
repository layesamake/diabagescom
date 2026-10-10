<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property int $position
 * @property string $product_id
 * @property string $sku
 * @property string $label
 * @property string $unit_code
 * @property string $quantity
 * @property string $quantity_received
 * @property int $unit_cost
 * @property int $tax_rate_basis_points
 * @property int $line_excl_tax
 * @property int $line_tax
 * @property int $line_total
 * @property-read Product $product
 */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_cost' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'line_excl_tax' => 'integer',
            'line_tax' => 'integer',
            'line_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
