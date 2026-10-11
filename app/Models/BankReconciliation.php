<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapprochement d'un compte de trésorerie avec un relevé (banque, Wave...).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property CarbonImmutable $statement_date
 * @property int $statement_balance
 * @property string|null $reference
 * @property string $status
 * @property string $created_by
 * @property string|null $completed_by
 * @property CarbonImmutable|null $completed_at
 * @property-read ChartAccount $account
 */
class BankReconciliation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'statement_balance' => 'integer', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ChartAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'account_id');
    }
}
