<?php

namespace App\Domain\Billing;

/**
 * Prestataires de paiement disponibles. Les prestataires réels (Wave, Orange
 * Money, carte) ne sont ajoutés qu'après contrat, clés officielles en
 * variables d'environnement et validation explicite du propriétaire.
 */
final class ProviderRegistry
{
    /** @var array<string, BillingProvider> */
    private array $providers;

    public function __construct(SandboxProvider $sandbox)
    {
        $this->providers = [$sandbox->code() => $sandbox];

        // Prestataires réels : classe déclarée dans config/billing.php (« adapters »).
        foreach ((array) config('billing.adapters', []) as $class) {
            $adapter = is_string($class) ? app($class) : null;

            if ($adapter instanceof BillingProvider) {
                $this->providers[$adapter->code()] = $adapter;
            }
        }
    }

    public function find(string $code): ?BillingProvider
    {
        $provider = $this->providers[$code] ?? null;

        return $provider !== null && $this->usable($provider) ? $provider : null;
    }

    /**
     * Prestataire actif ET autorisé. En production, un prestataire réel exige en
     * plus la confirmation explicite du propriétaire (BILLING_LIVE_CONFIRMED) :
     * des clés présentes ne suffisent jamais à encaisser de l'argent réel.
     */
    private function usable(BillingProvider $provider): bool
    {
        if (! $provider->isEnabled()) {
            return false;
        }

        return $provider->code() === 'sandbox' || ! app()->isProduction() || (bool) config('billing.live_confirmed');
    }

    public function get(string $code): BillingProvider
    {
        return $this->find($code) ?? throw new InvalidBillingOperation(__('billing.errors.provider_unavailable'));
    }

    /**
     * Moyens proposés au client : prestataires actifs, envoi Wave au numéro de
     * DIABA GESCOM (s'il est renseigné) et virement.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $codes = array_keys(array_filter($this->providers, fn (BillingProvider $p) => $this->usable($p)));

        if (filled(config('billing.wave_number'))) {
            $codes[] = 'wave_manual';
        }

        return [...$codes, 'transfer'];
    }
}
