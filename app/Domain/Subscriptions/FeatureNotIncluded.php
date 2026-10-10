<?php

namespace App\Domain\Subscriptions;

/** Fonction non incluse dans la formule (ex. comptabilité). */
final class FeatureNotIncluded extends SubscriptionRestriction
{
    public function __construct(string $feature)
    {
        parent::__construct(__('subscription.errors.feature_not_included', [
            'feature' => __("subscription.features.{$feature}"),
        ]));
    }
}
