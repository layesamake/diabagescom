<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture d'abonnement DIABA GESCOM (formule, cycle, options), en XOF entiers.
 * Payée uniquement après un paiement vérifié (webhook signé ou virement validé).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $plan_id
 * @property string $billing_cycle
 * @property list<array{code: string, label: string, quantity: int, unit_price: int, amount: int}> $lines
 * @property int $total
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable $due_on
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $paid_at
 * @property int $reminder_stage
 * @property string|null $created_by
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 * @property-read Plan $plan
 * @property-read Tenant $tenant
 * @property-read Collection<int, SaasCreditNote> $creditNotes
 */
class SaasInvoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const string OPEN = 'open';

    public const string PAID = 'paid';

    public const string VOID = 'void';

    protected $guarded = [];

    protected $attributes = ['status' => self::OPEN, 'currency' => 'XOF', 'reminder_stage' => 0];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'total' => 'integer',
            'due_on' => 'date',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'reminder_stage' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SaasPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SaasPayment::class, 'invoice_id');
    }

    /**
     * @return HasMany<SaasCreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(SaasCreditNote::class, 'invoice_id');
    }
}
