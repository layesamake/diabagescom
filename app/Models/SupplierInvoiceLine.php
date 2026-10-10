<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $position
 * @property string|null $product_id
 * @property string $label
 * @property string $quantity
 * @property int $unit_cost
 * @property int $tax_rate_basis_points
 * @property int $line_excl_tax
 * @property int $line_tax
 * @property int $line_total
 */
class SupplierInvoiceLine extends Model
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
}
