<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une demande d'achat : instantané de l'article et quantité demandée.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_id
 * @property int $position
 * @property string $product_id
 * @property string $sku
 * @property string $label
 * @property string $unit_code
 * @property string $quantity
 * @property string|null $note
 * @property-read Product $product
 */
class PurchaseRequestLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
