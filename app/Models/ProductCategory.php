<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catégorie d'articles (arbre ; les cycles sont refusés par un trigger PostgreSQL).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $image_path
 * @property int $version
 * @property CarbonImmutable|null $archived_at
 * @property-read ProductCategory|null $parent
 */
class ProductCategory extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['parent_id', 'name'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'version' => 'integer'];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
