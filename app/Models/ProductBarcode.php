<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code-barres d'un article ; unique dans l'entreprise (contrainte PostgreSQL).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $code
 * @property string $symbology
 * @property bool $is_primary
 * @property-read Product $product
 */
class ProductBarcode extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['product_id', 'code', 'symbology', 'is_primary'];

    protected $attributes = ['is_primary' => false];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
