<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $entry_id
 * @property int $position
 * @property string $account_id
 * @property int $debit
 * @property int $credit
 * @property string $label
 * @property string|null $party_type
 * @property string|null $party_id
 * @property-read ChartAccount $account
 * @property-read JournalEntry $entry
 */
class JournalEntryLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $attributes = ['debit' => 0, 'credit' => 0];

    protected function casts(): array
    {
        return ['position' => 'integer', 'debit' => 'integer', 'credit' => 'integer'];
    }

    /**
     * @return BelongsTo<ChartAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
}
