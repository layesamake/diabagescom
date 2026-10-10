<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $receipt_id
 * @property string|null $order_line_id
 * @property string $product_id
 * @property string $sku
 * @property string $label
 * @property string $unit_code
 * @property string $quantity
 * @property int $unit_cost
 * @property int $tax_rate_basis_points
 * @property int $line_excl_tax
 */
class GoodsReceiptLine extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_cost' => 'integer', 'tax_rate_basis_points' => 'integer', 'line_excl_tax' => 'integer'];
    }
}
