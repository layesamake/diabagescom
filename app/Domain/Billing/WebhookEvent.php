<?php

namespace App\Domain\Billing;

/**
 * Événement de paiement vérifié (signature contrôlée) et normalisé.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  charge utile expurgée (sans données personnelles ni secret)
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $reference,
        public int $amount,
        public bool $succeeded,
        public array $payload,
    ) {}
}
