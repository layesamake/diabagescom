<?php

namespace App\Domain\Subscriptions;

use App\Models\Plan;

/**
 * Crée les formules par défaut absentes (config/diaba.php). Ne modifie jamais
 * une formule existante : une fois créée, elle appartient au super-administrateur.
 */
final class SyncPlans
{
    /**
     * @return int nombre de formules créées
     */
    public function __invoke(): int
    {
        $created = 0;

        /** @var array<string, array<string, mixed>> $plans */
        $plans = config('diaba.default_plans');

        foreach ($plans as $code => $attributes) {
            $plan = Plan::query()->firstOrCreate(['code' => $code], [...$attributes, 'active' => true]);
            $created += $plan->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
