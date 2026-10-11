<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Webhook reçu d'un prestataire de paiement (hors contexte entreprise),
 * dédupliqué par (prestataire, identifiant d'événement). Charge utile expurgée.
 *
 * @property string $id
 * @property string $provider
 * @property string $external_event_id
 * @property string|null $event_type
 * @property bool $signature_valid
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $error
 * @property string|null $payment_id
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 */
class PaymentProviderEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signature_valid' => 'boolean', 'payload' => 'array', 'processed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
