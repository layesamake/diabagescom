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
    }

    public function find(string $code): ?BillingProvider
    {
        $provider = $this->providers[$code] ?? null;

        return $provider !== null && $provider->isEnabled() ? $provider : null;
    }

    public function get(string $code): BillingProvider
    {
        return $this->find($code) ?? throw new InvalidBillingOperation(__('billing.errors.provider_unavailable'));
    }

    /**
     * Moyens proposés au client : prestataires actifs + virement.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $codes = array_keys(array_filter($this->providers, fn (BillingProvider $p) => $p->isEnabled()));

        return [...$codes, 'transfer'];
    }
}
