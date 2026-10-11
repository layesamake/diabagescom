<?php

namespace App\Domain\Billing;

use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Prestataire de démonstration : reproduit le contrat d'un vrai prestataire
 * (page de paiement, webhook signé HMAC-SHA256 horodaté) sans argent réel.
 *
 * En-tête : `X-Diaba-Signature: t=<timestamp>,v1=<hmac(secret, "t.body")>`.
 */
final class SandboxProvider implements BillingProvider
{
    public function code(): string
    {
        return 'sandbox';
    }

    public function isEnabled(): bool
    {
        return (bool) config('billing.providers.sandbox.enabled') && filled(config('billing.providers.sandbox.secret'));
    }

    public function checkout(SaasPayment $payment, SaasInvoice $invoice): array
    {
        $reference = 'SBX-'.Str::upper(Str::random(16));

        return ['reference' => $reference, 'checkout_url' => route('billing.sandbox.show', $reference)];
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $body = $request->getContent();

        if (! $this->verify($body, (string) $request->header('X-Diaba-Signature'))) {
            return null;
        }

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['id'], $data['type'], $data['data']['reference'], $data['data']['amount'])) {
            return null;
        }

        return new WebhookEvent(
            eventId: (string) $data['id'],
            type: (string) $data['type'],
            reference: (string) $data['data']['reference'],
            amount: (int) $data['data']['amount'],
            succeeded: $data['type'] === 'payment.succeeded',
            payload: ['type' => $data['type'], 'reference' => $data['data']['reference'], 'amount' => (int) $data['data']['amount']],
        );
    }

    /**
     * Signature d'un corps (utilisée par la page de simulation et les tests).
     */
    public function sign(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", (string) config('billing.providers.sandbox.secret'));
    }

    private function verify(string $body, string $header): bool
    {
        if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $matches)) {
            return false;
        }

        $secret = (string) config('billing.providers.sandbox.secret');

        if ($secret === '' || abs(time() - (int) $matches[1]) > (int) config('billing.webhook_tolerance', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$matches[1]}.{$body}", $secret), $matches[2]);
    }
}
