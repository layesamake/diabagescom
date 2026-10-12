<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avoir sur une facture d'abonnement payée (remboursement, geste commercial).
 * Émis uniquement par App\Domain\Billing\SaasBilling::issueCreditNote().
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $number
 * @property int $amount
 * @property string $currency
 * @property string $reason
 * @property string $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read SaasInvoice $invoice
 */
class SaasCreditNote extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $attributes = ['currency' => 'XOF'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<SaasInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasInvoice::class, 'invoice_id');
    }
}
