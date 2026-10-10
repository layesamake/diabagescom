<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de document de vente : instantané de l'article (libellé, prix, taxe),
 * jamais relu depuis le catalogue après validation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $position
 * @property string $product_id
 * @property string $sku
 * @property string $label
 * @property string $unit_code
 * @property string $product_type
 * @property string $quantity
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $tax_rate_basis_points
 * @property int $line_total
 * @property int $line_excl_tax
 * @property int $line_tax
 * @property int|null $unit_cost
 * @property string|null $source_line_id
 * @property bool $restock
 * @property-read Product $product
 * @property-read SalesDocument $document
 */
class SalesDocumentLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $attributes = ['discount_amount' => 0, 'restock' => false];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'line_total' => 'integer',
            'line_excl_tax' => 'integer',
            'line_tax' => 'integer',
            'unit_cost' => 'integer',
            'restock' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }
}
