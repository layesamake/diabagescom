<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres comptables d'une entreprise (une ligne par entreprise).
 *
 * @property string $tenant_id
 * @property CarbonImmutable $posting_starts_on
 * @property string|null $expert_name
 * @property CarbonImmutable|null $expert_validated_at
 * @property string|null $expert_validated_by
 * @property string $initialized_by
 */
class AccountingSetting extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posting_starts_on' => 'date', 'expert_validated_at' => 'datetime'];
    }
}
