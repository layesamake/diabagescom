<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de paiement d'une facture SaaS auprès d'un prestataire
 * (bac à sable, Wave, Orange Money, carte) ou par virement validé manuellement.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $provider
 * @property string $status
 * @property int $amount
 * @property string $external_reference
 * @property string|null $checkout_url
 * @property string|null $proof_reference
 * @property string|null $failure_reason
 * @property string $idempotency_key
 * @property string|null $created_by
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property-read SaasInvoice $invoice
 */
class SaasPayment extends Model
{
    use BelongsToTenant, HasUuids;

    public const string PENDING = 'pending';

    public const string MANUAL_REVIEW = 'pending_manual_review';

    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const string REQUIRES_REVIEW = 'requires_review';

    protected $guarded = [];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'verified_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SaasInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasInvoice::class, 'invoice_id');
    }
}
