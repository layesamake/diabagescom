<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $status
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 */
class FiscalYear extends Model
{
    use BelongsToTenant, HasUuids;

    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $guarded = [];

    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('starts_on');
    }
}
