<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux de taxe en points de base (1800 = 18 %), jamais en flottant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $rate_basis_points
 * @property bool $is_default
 * @property bool $active
 */
class TaxRate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'rate_basis_points', 'is_default', 'active'];

    protected $attributes = ['is_default' => false, 'active' => true];

    protected function casts(): array
    {
        return ['rate_basis_points' => 'integer', 'is_default' => 'boolean', 'active' => 'boolean'];
    }
}
