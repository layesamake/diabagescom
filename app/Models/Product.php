<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article ou service. Montants en XOF entiers. Modifications uniquement via
 * App\Domain\Catalog\ProductService (permissions, audit, version).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string|null $category_id
 * @property string $unit_id
 * @property string $tax_rate_id
 * @property int $sale_price
 * @property int $standard_cost
 * @property string|null $image_path
 * @property bool $active
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Unit $unit
 * @property-read TaxRate $taxRate
 * @property-read ProductCategory|null $category
 */
class Product extends Model
{
    use BelongsToTenant, HasUuids;

    public const array TYPES = ['stockable', 'service', 'consumable'];

    protected $attributes = ['active' => true, 'version' => 1, 'sale_price' => 0, 'standard_cost' => 0];

    protected function casts(): array
    {
        return [
            'sale_price' => 'integer',
            'standard_cost' => 'integer',
            'active' => 'boolean',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * @return HasMany<ProductBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /**
     * Recherche insensible à la casse et aux accents sur le nom, le SKU et les codes-barres.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw('diaba_search_text(products.name) LIKE diaba_search_text(?)', [$like])
            ->orWhereRaw('upper(products.sku) = upper(?)', [$term])
            ->orWhereRaw('upper(products.sku) LIKE upper(?)', [$like])
            ->orWhereHas('barcodes', fn (Builder $b) => $b->whereRaw('upper(code) = upper(?)', [$term])));
    }
}
