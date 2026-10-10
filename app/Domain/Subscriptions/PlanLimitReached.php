<?php

namespace App\Domain\Subscriptions;

/** Limite de la formule atteinte (dépôts, utilisateurs). */
final class PlanLimitReached extends SubscriptionRestriction
{
    public function __construct(string $resource, int $limit)
    {
        parent::__construct(__("subscription.errors.limit_{$resource}", ['limit' => $limit]));
    }
}
