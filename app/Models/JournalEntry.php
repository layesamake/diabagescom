<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Écriture comptable : brouillon (saisie manuelle) puis validée (numérotée,
 * équilibrée, immuable). Une correction passe par une écriture d'extourne liée.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $journal_id
 * @property string $period_id
 * @property string|null $number
 * @property CarbonImmutable $entry_date
 * @property string $status
 * @property string $description
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $source_kind
 * @property string|null $reversal_of_id
 * @property string|null $created_by
 * @property string|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $created_at
 * @property-read AccountingJournal $journal
 * @property-read AccountingPeriod $period
 * @property-read JournalEntry|null $reversal
 * @property-read JournalEntry|null $reversalOf
 */
class JournalEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string POSTED = 'posted';

    protected $guarded = [];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'entry_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<AccountingJournal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'journal_id');
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    /**
     * @return HasOne<JournalEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }
}
