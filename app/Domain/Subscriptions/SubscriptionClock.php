<?php

namespace App\Domain\Subscriptions;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Règles temporelles du cycle (docs/01) : essai → lecture seule (7 jours) → suspension.
 *
 * Le statut effectif est calculé à partir des dates : l'accès reste correct même
 * si le planificateur n'a pas encore persisté la transition. Les échéances sont
 * calculées depuis la date de fin précédente, jamais depuis l'instant du
 * traitement, pour qu'un retard ne prolonge pas gratuitement l'accès.
 */
final class SubscriptionClock
{
    /**
     * @return array{status: string, read_only_ends_at: CarbonImmutable|null}
     */
    public static function effective(Subscription $subscription, CarbonImmutable $now): array
    {
        $status = $subscription->status;
        $readOnlyEndsAt = $subscription->read_only_ends_at;
        $graceDays = (int) config('diaba.subscriptions.read_only_days');

        if ($status === Subscription::TRIALING && $subscription->trial_ends_at !== null && $now->gte($subscription->trial_ends_at)) {
            $status = Subscription::READ_ONLY;
            $readOnlyEndsAt = $subscription->trial_ends_at->addDays($graceDays);
        }

        if ($status === Subscription::ACTIVE && $subscription->current_period_end !== null && $now->gte($subscription->current_period_end)) {
            $status = Subscription::READ_ONLY;
            $readOnlyEndsAt = $subscription->current_period_end->addDays($graceDays);
        }

        if ($status === Subscription::READ_ONLY && $readOnlyEndsAt !== null && $now->gte($readOnlyEndsAt)) {
            $status = Subscription::SUSPENDED;
        }

        return ['status' => $status, 'read_only_ends_at' => $readOnlyEndsAt];
    }
}
