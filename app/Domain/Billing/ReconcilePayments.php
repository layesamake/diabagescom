<?php

namespace App\Domain\Billing;

use App\Domain\Tenancy\TenantScope;
use App\Models\SaasPayment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rapprochement des paiements en ligne restés « en attente » : on interroge le
 * prestataire (le webhook a pu se perdre), puis on annule ceux qui n'ont pas
 * abouti au bout du délai. Idempotent ; une erreur réseau sur un paiement
 * n'empêche pas les suivants et sera réessayée au passage suivant.
 */
final class ReconcilePayments
{
    private const int BATCH = 200;

    public function __construct(private readonly SaasBilling $billing) {}

    /**
     * @return array{checked: int, settled: int, expired: int, errors: int}
     */
    public function __invoke(): array
    {
        $result = ['checked' => 0, 'settled' => 0, 'expired' => 0, 'errors' => 0];

        $pending = SaasPayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', SaasPayment::PENDING)
            ->whereNotIn('provider', SaasPayment::MANUAL_PROVIDERS)
            ->where('created_at', '<=', now()->subMinutes((int) config('billing.reconcile_after_minutes', 3)))
            ->orderBy('created_at')->limit(self::BATCH)->get();

        foreach ($pending as $payment) {
            $result['checked']++;

            try {
                $outcome = $this->billing->reconcile($payment);

                if ($outcome === 'skipped') {
                    continue; // prestataire désactivé : on ne touche pas au paiement
                }

                if ($payment->refresh()->status === SaasPayment::PENDING
                    && $payment->created_at !== null
                    && $payment->created_at->lte(now()->subHours((int) config('billing.expire_after_hours', 24)))
                    && $this->billing->expire($payment)) {
                    $result['expired']++;
                } elseif ($payment->status === SaasPayment::SUCCEEDED) {
                    $result['settled']++;
                }
            } catch (Throwable $e) {
                $result['errors']++;
                Log::warning('billing.reconcile_failed', ['payment' => $payment->id, 'provider' => $payment->provider, 'error' => $e::class]);
            }
        }

        return $result;
    }
}
