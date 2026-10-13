<?php

namespace App\Domain\Billing;

use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use Illuminate\Http\Request;

/**
 * Prestataire de paiement (docs/06, « BillingProvider ») : création du
 * paiement et vérification cryptographique des webhooks. L'état de
 * l'abonnement ne change jamais sur la seule foi du navigateur du client.
 */
interface BillingProvider
{
    public function code(): string;

    public function isEnabled(): bool;

    /**
     * @return array{reference: string, checkout_url: string|null}
     */
    public function checkout(SaasPayment $payment, SaasInvoice $invoice): array;

    /**
     * Interroge le prestataire sur l'issue d'un paiement (rapprochement quand un
     * webhook est perdu). null = rien de nouveau. L'identifiant de l'événement
     * doit être stable pour un même état (ex. « poll:<référence>:<état> ») afin
     * que des interrogations répétées ne s'appliquent qu'une fois.
     */
    public function status(SaasPayment $payment): ?WebhookEvent;

    /**
     * Événement vérifié, ou null si la signature (ou l'horodatage) est invalide.
     */
    public function parseWebhook(Request $request): ?WebhookEvent;
}
