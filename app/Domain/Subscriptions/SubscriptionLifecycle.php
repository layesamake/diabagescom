<?php

namespace App\Domain\Subscriptions;

use App\Domain\Audit\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seul point de modification des souscriptions : transactionnel, verrouillé,
 * audité. Les actions manuelles (console plateforme) exigent un motif.
 */
final class SubscriptionLifecycle
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly FeatureAccess $access,
        private readonly TenantContext $context,
    ) {}

    /**
     * Démarre l'essai gratuit. À appeler dans la transaction de création de l'entreprise.
     */
    public function startTrial(Tenant $tenant, Plan $plan, ?User $actor = null): Subscription
    {
        if (! $plan->active) {
            throw new InvalidArgumentException('Inactive plan.');
        }

        $now = CarbonImmutable::now();

        $subscription = $this->context->runAs($tenant, function () use ($tenant, $plan, $now): Subscription {
            $subscription = new Subscription;
            $subscription->tenant_id = $tenant->id;
            $subscription->plan_id = $plan->id;
            $subscription->status = Subscription::TRIALING;
            $subscription->trial_ends_at = $now->addDays((int) config('diaba.subscriptions.trial_days'));
            $subscription->status_changed_at = $now;
            $subscription->save();

            return $subscription;
        });

        $this->audit->record('subscription.trial_started', $subscription, after: [
            'plan' => $plan->code,
            'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
        ], actor: $actor, tenant: $tenant);

        $this->access->forget($tenant);

        return $subscription;
    }

    /**
     * Verrouille la souscription courante (à utiliser avant un contrôle de limite).
     */
    public function lockForTenant(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('status', '<>', Subscription::CANCELLED)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Persiste les transitions échues (tâche planifiée). Idempotent.
     *
     * @return list<array{subscription: Subscription, from: string, to: string}>
     */
    public function applyDueTransitions(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $changes = [];

        $candidates = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', [Subscription::TRIALING, Subscription::ACTIVE, Subscription::READ_ONLY])
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Subscription::TRIALING)->where('trial_ends_at', '<=', $now))
                ->orWhere(fn ($q) => $q->where('status', Subscription::ACTIVE)->where('current_period_end', '<=', $now))
                ->orWhere(fn ($q) => $q->where('status', Subscription::READ_ONLY)->where('read_only_ends_at', '<=', $now)))
            ->pluck('id');

        foreach ($candidates as $id) {
            $change = DB::transaction(function () use ($id, $now): ?array {
                /** @var Subscription|null $subscription */
                $subscription = Subscription::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($id);

                if ($subscription === null) {
                    return null;
                }

                $from = $subscription->status;
                $effective = SubscriptionClock::effective($subscription, $now);

                if ($effective['status'] === $from) {
                    return null;
                }

                $subscription->status = $effective['status'];
                $subscription->read_only_ends_at = $effective['read_only_ends_at'];
                $subscription->status_changed_at = $now;
                $subscription->save();

                $this->audit->record('subscription.status_changed', $subscription,
                    before: ['status' => $from],
                    after: ['status' => $subscription->status],
                    metadata: ['reason' => 'schedule'],
                    tenant: $this->tenantOf($subscription));

                return ['subscription' => $subscription, 'from' => $from, 'to' => $subscription->status];
            });

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function changePlan(Subscription $subscription, Plan $plan, string $reason, User $actor): Subscription
    {
        return $this->manual($subscription, $reason, $actor, 'subscription.plan_changed', function (Subscription $s) use ($plan): void {
            $s->plan_id = $plan->id;
        });
    }

    public function extendTrial(Subscription $subscription, int $days, string $reason, User $actor): Subscription
    {
        if ($days < 1 || $days > 60) {
            throw new InvalidArgumentException('Trial extension must be between 1 and 60 days.');
        }

        return $this->manual($subscription, $reason, $actor, 'subscription.trial_extended', function (Subscription $s) use ($days): void {
            $base = CarbonImmutable::now()->max($s->trial_ends_at ?? CarbonImmutable::now());
            $s->status = Subscription::TRIALING;
            $s->trial_ends_at = $base->addDays($days);
            $s->read_only_ends_at = null;
        });
    }

    /**
     * Activation manuelle (ex. virement validé par le super-admin, docs/09).
     */
    public function activate(Subscription $subscription, string $billingCycle, string $reason, User $actor): Subscription
    {
        if (! in_array($billingCycle, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Invalid billing cycle.');
        }

        return $this->manual($subscription, $reason, $actor, 'subscription.activated', function (Subscription $s) use ($billingCycle): void {
            $start = CarbonImmutable::now();
            $s->status = Subscription::ACTIVE;
            $s->billing_cycle = $billingCycle;
            $s->current_period_start = $start;
            $s->current_period_end = $billingCycle === 'yearly' ? $start->addYear() : $start->addMonth();
            $s->read_only_ends_at = null;
        });
    }

    /**
     * Facture SaaS payée et vérifiée (webhook signé ou virement validé) :
     * formule et cycle de la facture, période prolongée (un renouvellement anticipé
     * ne fait perdre aucun jour), options achetées actives sur la période.
     * Appelée uniquement par App\Domain\Billing\SaasBilling, dans sa transaction.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function applyPaidInvoice(SaasInvoice $invoice, ?User $actor): array
    {
        /** @var Subscription $locked */
        $locked = Subscription::query()->withoutGlobalScope(TenantScope::class)->with('plan')
            ->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->firstOrFail();

        $before = $this->snapshot($locked);
        $now = CarbonImmutable::now();
        $start = $locked->status === Subscription::ACTIVE && $locked->current_period_end?->isAfter($now)
            ? $locked->current_period_end
            : $now;
        $end = $invoice->billing_cycle === 'yearly' ? $start->addYear() : $start->addMonth();

        $locked->plan_id = $invoice->plan_id;
        $locked->status = Subscription::ACTIVE;
        $locked->billing_cycle = $invoice->billing_cycle;
        $locked->current_period_start = $start;
        $locked->current_period_end = $end;
        $locked->read_only_ends_at = null;
        $locked->status_changed_at = $now;
        $locked->save();
        $locked->load('plan');

        foreach ($invoice->lines as $line) {
            if (in_array($line['code'], [SubscriptionAddon::EXTRA_USER, SubscriptionAddon::EXTRA_WAREHOUSE], true)) {
                $addon = new SubscriptionAddon;
                $addon->forceFill([
                    'tenant_id' => $invoice->tenant_id,
                    'subscription_id' => $locked->id,
                    'addon_code' => $line['code'],
                    'quantity' => $line['quantity'],
                    'starts_at' => $start,
                    'ends_at' => $end,
                ]);
                $this->context->runAs($this->tenantOf($locked), fn () => $addon->save());
            }
        }

        $this->audit->record('subscription.paid', $locked,
            before: $before,
            after: $this->snapshot($locked),
            metadata: ['invoice' => $invoice->number, 'amount' => $invoice->total],
            actor: $actor,
            tenant: $this->tenantOf($locked));

        $this->access->forget($this->tenantOf($locked));

        return ['start' => $start, 'end' => $end];
    }

    public function suspend(Subscription $subscription, string $reason, User $actor): Subscription
    {
        return $this->manual($subscription, $reason, $actor, 'subscription.suspended', function (Subscription $s): void {
            $s->status = Subscription::SUSPENDED;
        });
    }

    /**
     * @param  callable(Subscription): void  $mutate
     */
    private function manual(Subscription $subscription, string $reason, User $actor, string $action, callable $mutate): Subscription
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('A reason is required.');
        }

        return DB::transaction(function () use ($subscription, $reason, $actor, $action, $mutate): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->withoutGlobalScope(TenantScope::class)->with('plan')->lockForUpdate()->findOrFail($subscription->id);

            $before = $this->snapshot($locked);
            $mutate($locked);
            $locked->status_changed_at = CarbonImmutable::now();
            $locked->save();
            $locked->load('plan');

            $this->audit->record($action, $locked,
                before: $before,
                after: $this->snapshot($locked),
                metadata: ['reason' => $reason, 'via' => 'platform_console'],
                actor: $actor,
                tenant: $this->tenantOf($locked));

            $this->access->forget($this->tenantOf($locked));

            return $locked;
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(Subscription $subscription): array
    {
        return [
            'status' => $subscription->status,
            'plan' => $subscription->plan->code,
            'billing_cycle' => $subscription->billing_cycle,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
        ];
    }

    private function tenantOf(Subscription $subscription): Tenant
    {
        return Tenant::query()->findOrFail($subscription->tenant_id);
    }
}
