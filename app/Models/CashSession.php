<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : ouverture (fond de caisse) → encaissements, dépenses →
 * fermeture (montant compté, écart justifié). Une seule session ouverte par caisse.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $register_id
 * @property string $status
 * @property int $opening_amount
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $expected_amount
 * @property int|null $counted_amount
 * @property int|null $difference
 * @property string|null $closing_note
 * @property array{opening?: array<int, int>, closing?: array<int, int>}|null $count_details
 * @property int $version
 * @property-read CashRegister $register
 * @property-read User $opener
 * @property-read User|null $closer
 */
class CashSession extends Model
{
    use BelongsToTenant, HasUuids;

    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $guarded = [];

    protected $attributes = ['status' => self::OPEN, 'version' => 1];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'integer',
            'expected_amount' => 'integer',
            'counted_amount' => 'integer',
            'difference' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
            'count_details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'session_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_session_id');
    }
}
