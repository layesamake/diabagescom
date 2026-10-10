<?php

namespace App\Domain\Subscriptions;

/** Accès suspendu : seules la page d'abonnement et la déconnexion restent accessibles. */
final class SubscriptionSuspended extends SubscriptionRestriction
{
    public function __construct()
    {
        parent::__construct(__('subscription.errors.suspended'));
    }
}
