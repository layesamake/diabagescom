<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Période mensuelle : ouverte, verrouillée (réversible) puis clôturée (définitif).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $fiscal_year_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $status
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property-read FiscalYear $fiscalYear
 */
class AccountingPeriod extends Model
{
    use BelongsToTenant, HasUuids;

    public const string OPEN = 'open';

    public const string LOCKED = 'locked';

    public const string CLOSED = 'closed';

    protected $guarded = [];

    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
