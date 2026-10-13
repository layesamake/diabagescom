<?php

namespace Tests\Support;

use App\Domain\Billing\BillingProvider;
use App\Domain\Billing\WebhookEvent;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Prestataire factice pour tester le cadre commun (rapprochement, expiration,
 * garde-fous) sans compte marchand. Il suit le même contrat qu'un vrai
 * prestataire : webhook signé HMAC et interrogation de l'état d'un paiement.
 */
final class FakeBillingProvider implements BillingProvider
{
    public const string SECRET = 'fake-provider-secret';

    /** @var array<string, array{state: string, amount: int}> état « côté prestataire », par référence */
    public static array $remote = [];

    public static bool $enabled = true;

    public static bool $failing = false;

    public function code(): string
    {
        return 'fake';
    }

    public function isEnabled(): bool
    {
        return self::$enabled;
    }

    public function checkout(SaasPayment $payment, SaasInvoice $invoice): array
    {
        $reference = 'FAKE-'.Str::upper(Str::random(12));
        self::$remote[$reference] = ['state' => 'pending', 'amount' => $payment->amount];

        return ['reference' => $reference, 'checkout_url' => 'https://pay.example.test/'.$reference];
    }

    public function status(SaasPayment $payment): ?WebhookEvent
    {
        if (self::$failing) {
            throw new \RuntimeException('prestataire injoignable');
        }

        $remote = self::$remote[$payment->external_reference] ?? null;

        if ($remote === null || $remote['state'] === 'pending') {
            return null;
        }

        return new WebhookEvent(
            eventId: "poll:{$payment->external_reference}:{$remote['state']}",
            type: "payment.{$remote['state']}",
            reference: $payment->external_reference,
            amount: $remote['amount'],
            succeeded: $remote['state'] === 'succeeded',
            payload: ['reference' => $payment->external_reference, 'state' => $remote['state']],
        );
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $body = $request->getContent();

        if (! hash_equals(self::sign($body), (string) $request->header('X-Fake-Signature'))) {
            return null;
        }

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['id'], $data['reference'], $data['amount'], $data['state'])) {
            return null;
        }

        return new WebhookEvent(
            eventId: (string) $data['id'],
            type: 'payment.'.$data['state'],
            reference: (string) $data['reference'],
            amount: (int) $data['amount'],
            succeeded: $data['state'] === 'succeeded',
            payload: ['reference' => $data['reference'], 'state' => $data['state']],
        );
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }

    public static function reset(): void
    {
        self::$remote = [];
        self::$enabled = true;
        self::$failing = false;
    }
}
