<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement d'espèces dans le tiroir (append-only). Montant signé :
 * positif = entrée, négatif = sortie.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property string $type
 * @property int $amount
 * @property string|null $payment_id
 * @property string|null $label
 * @property string|null $reason
 * @property string|null $number
 * @property string $idempotency_key
 * @property string $created_by
 * @property CarbonImmutable $occurred_at
 * @property-read User $author
 * @property-read Payment|null $payment
 */
class CashMovement extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
