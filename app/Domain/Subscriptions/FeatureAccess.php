<?php

namespace App\Domain\Subscriptions;

use App\Domain\Tenancy\TenantScope;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Éligibilité d'une entreprise selon sa souscription (EPIC-02).
 *
 * Point de décision unique, utilisé par les routes (middleware), le menu
 * (données partagées), les jobs et les services métier avant toute mutation.
 * Sans souscription courante, l'entreprise est considérée suspendue (échec fermé).
 */
final class FeatureAccess
{
    public const string FEATURE_ACCOUNTING = 'accounting';

    public const string FEATURE_ONLINE_STORE = 'online_store';

    public const string RESOURCE_WAREHOUSES = 'warehouses';

    public const string RESOURCE_USERS = 'users';

    /** @var array<string, Subscription|null> */
    private array $subscriptions = [];

    public function subscription(Tenant $tenant): ?Subscription
    {
        if (! array_key_exists($tenant->id, $this->subscriptions)) {
            $this->subscriptions[$tenant->id] = Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->with('plan')
                ->where('tenant_id', $tenant->id)
                ->where('status', '<>', Subscription::CANCELLED)
                ->first();
        }

        return $this->subscriptions[$tenant->id];
    }

    public function forget(Tenant $tenant): void
    {
        unset($this->subscriptions[$tenant->id]);
    }

    public function status(Tenant $tenant, ?CarbonImmutable $now = null): string
    {
        $subscription = $this->subscription($tenant);

        if ($subscription === null) {
            return Subscription::SUSPENDED;
        }

        return SubscriptionClock::effective($subscription, $now ?? CarbonImmutable::now())['status'];
    }

    public function canWrite(Tenant $tenant): bool
    {
        return in_array($this->status($tenant), [Subscription::TRIALING, Subscription::ACTIVE], true);
    }

    public function isSuspended(Tenant $tenant): bool
    {
        return $this->status($tenant) === Subscription::SUSPENDED;
    }

    public function hasFeature(Tenant $tenant, string $feature): bool
    {
        $plan = $this->subscription($tenant)?->plan;

        return match ($feature) {
            self::FEATURE_ACCOUNTING => $plan !== null && $plan->accounting_enabled,
            self::FEATURE_ONLINE_STORE => $plan !== null && $plan->online_store_enabled,
            default => false,
        };
    }

    /**
     * Limite effective (formule + options actives) ; null = illimité.
     */
    public function limit(Tenant $tenant, string $resource): ?int
    {
        $subscription = $this->subscription($tenant);

        if ($subscription === null) {
            return 0;
        }

        [$base, $addonCode] = match ($resource) {
            self::RESOURCE_WAREHOUSES => [$subscription->plan->warehouse_limit, SubscriptionAddon::EXTRA_WAREHOUSE],
            self::RESOURCE_USERS => [$subscription->plan->included_users, SubscriptionAddon::EXTRA_USER],
            default => throw new InvalidArgumentException("Unknown resource [{$resource}]."),
        };

        if ($base === null) {
            return null;
        }

        $extra = (int) SubscriptionAddon::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('subscription_id', $subscription->id)
            ->where('addon_code', $addonCode)
            ->where('status', 'active')
            ->where('starts_at', '<=', CarbonImmutable::now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', CarbonImmutable::now()))
            ->sum('quantity');

        return $base + $extra;
    }

    public function usage(Tenant $tenant, string $resource): int
    {
        return match ($resource) {
            self::RESOURCE_WAREHOUSES => Warehouse::query()->forTenant($tenant)->where('status', Warehouse::STATUS_ACTIVE)->count(),
            self::RESOURCE_USERS => TenantMembership::query()->where('tenant_id', $tenant->id)->where('status', TenantMembership::STATUS_ACTIVE)->count(),
            default => throw new InvalidArgumentException("Unknown resource [{$resource}]."),
        };
    }

    /**
     * @throws SubscriptionReadOnly|SubscriptionSuspended
     */
    public function assertCanWrite(Tenant $tenant): void
    {
        match ($this->status($tenant)) {
            Subscription::TRIALING, Subscription::ACTIVE => null,
            Subscription::READ_ONLY => throw new SubscriptionReadOnly,
            default => throw new SubscriptionSuspended,
        };
    }

    /**
     * @throws FeatureNotIncluded|SubscriptionSuspended
     */
    public function assertFeature(Tenant $tenant, string $feature): void
    {
        if ($this->isSuspended($tenant)) {
            throw new SubscriptionSuspended;
        }

        if (! $this->hasFeature($tenant, $feature)) {
            throw new FeatureNotIncluded($feature);
        }
    }

    /**
     * À appeler dans la transaction qui crée la ressource, après verrouillage
     * de la souscription (SubscriptionLifecycle::lockForTenant), pour éviter
     * qu'une création concurrente ne dépasse la limite.
     *
     * @throws PlanLimitReached|SubscriptionReadOnly|SubscriptionSuspended
     */
    public function assertCanAdd(Tenant $tenant, string $resource, int $quantity = 1): void
    {
        $this->assertCanWrite($tenant);

        $limit = $this->limit($tenant, $resource);

        if ($limit !== null && $this->usage($tenant, $resource) + $quantity > $limit) {
            throw new PlanLimitReached($resource, $limit);
        }
    }

    /**
     * Résumé transmis à l'interface (affichage uniquement).
     *
     * @return array<string, mixed>
     */
    public function summary(Tenant $tenant): array
    {
        $subscription = $this->subscription($tenant);
        $now = CarbonImmutable::now();
        $effective = $subscription !== null ? SubscriptionClock::effective($subscription, $now) : null;

        return [
            'status' => $effective['status'] ?? Subscription::SUSPENDED,
            'plan' => $subscription ? ['code' => $subscription->plan->code, 'name' => $subscription->plan->name] : null,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'cancel_at' => $subscription?->cancel_at?->toIso8601String(),
            'read_only_ends_at' => $effective['read_only_ends_at']?->toIso8601String(),
            'trial_days_left' => $subscription?->trial_ends_at !== null && ($effective['status'] ?? null) === Subscription::TRIALING
                ? max(0, (int) ceil($now->diffInHours($subscription->trial_ends_at, false) / 24))
                : null,
            'can_write' => in_array($effective['status'] ?? null, [Subscription::TRIALING, Subscription::ACTIVE], true),
            'features' => [
                self::FEATURE_ACCOUNTING => $this->hasFeature($tenant, self::FEATURE_ACCOUNTING),
                self::FEATURE_ONLINE_STORE => $this->hasFeature($tenant, self::FEATURE_ONLINE_STORE),
            ],
        ];
    }
}
