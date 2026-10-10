<?php

namespace App\Domain\Subscriptions;

/** Essai terminé ou impayé : consultation uniquement. */
final class SubscriptionReadOnly extends SubscriptionRestriction
{
    public function __construct()
    {
        parent::__construct(__('subscription.errors.read_only'));
    }
}
