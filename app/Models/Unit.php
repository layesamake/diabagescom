<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Unité de vente/stock (pièce, kg, litre…). decimals_allowed : quantités fractionnaires.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $decimals_allowed
 * @property bool $active
 */
class Unit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name', 'decimals_allowed', 'active'];

    protected $attributes = ['decimals_allowed' => false, 'active' => true];

    protected function casts(): array
    {
        return ['decimals_allowed' => 'boolean', 'active' => 'boolean'];
    }
}
