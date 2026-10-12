<?php

namespace App\Domain\Subscriptions;

use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicateurs de la console plateforme (propriétaire du logiciel) : revenu
 * récurrent, essais, conversions, résiliations, encaissements et impayés.
 *
 * Les statuts sont les statuts effectifs (SubscriptionClock), pas ceux stockés :
 * un essai échu compte comme terminé même si la tâche planifiée n'est pas encore passée.
 * Aucune donnée métier des entreprises n'est lue ici.
 */
final class PlatformMetrics
{
    /** Nombre de jours avant la fin d'essai à partir duquel on relance le client. */
    public const int TRIAL_ENDING_DAYS = 3;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $monthStart = $now->startOfMonth();
        $previousMonthStart = $monthStart->subMonth();

        $subscriptions = Subscription::query()->withoutGlobalScope(TenantScope::class)->with('plan')->get();
        $tenantNames = Tenant::query()->pluck('display_name', 'id');

        $statuses = array_fill_keys([Subscription::TRIALING, Subscription::ACTIVE, Subscription::READ_ONLY, Subscription::SUSPENDED, Subscription::CANCELLED], 0);
        $mrr = 0;
        $unpriced = 0;
        $trialsEnding = [];

        foreach ($subscriptions as $subscription) {
            $status = $subscription->status === Subscription::CANCELLED
                ? Subscription::CANCELLED
                : SubscriptionClock::effective($subscription, $now)['status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;

            if ($status === Subscription::ACTIVE) {
                $monthly = $this->monthlyValue($subscription);
                $monthly === null ? $unpriced++ : $mrr += $monthly;
            }

            if ($status === Subscription::TRIALING && $subscription->trial_ends_at !== null
                && $subscription->trial_ends_at->lte($now->addDays(self::TRIAL_ENDING_DAYS))) {
                $trialsEnding[] = [
                    'tenant_id' => $subscription->tenant_id,
                    'tenant' => $tenantNames[$subscription->tenant_id] ?? '?',
                    'plan' => $subscription->plan->name,
                    'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
                ];
            }
        }

        usort($trialsEnding, fn (array $a, array $b): int => strcmp($a['trial_ends_at'], $b['trial_ends_at']));

        $firstPayments = $this->firstPaymentByTenant();
        $paidTenants = $firstPayments->count();
        // Essais terminés = entreprises qui ne sont plus en essai (payées ou non).
        $trialsOver = $subscriptions->count() - $statuses[Subscription::TRIALING];

        $openInvoices = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('status', SaasInvoice::OPEN);
        $overdue = (clone $openInvoices)->whereDate('due_on', '<', $now->toDateString());

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'unpriced_active' => $unpriced,
            'statuses' => $statuses,
            'tenants_total' => $tenantNames->count(),
            'new_tenants' => [
                'this_month' => Tenant::query()->where('created_at', '>=', $monthStart)->count(),
                'last_month' => Tenant::query()->where('created_at', '>=', $previousMonthStart)->where('created_at', '<', $monthStart)->count(),
            ],
            'conversions' => [
                'this_month' => $firstPayments->filter(fn (CarbonImmutable $at) => $at->gte($monthStart))->count(),
                'rate' => $trialsOver > 0 ? round(100 * min($paidTenants, $trialsOver) / $trialsOver, 1) : null,
            ],
            'churn_this_month' => $this->churnedSince($monthStart),
            'collected' => [
                'this_month' => $this->collectedBetween($monthStart, $now),
                'last_month' => $this->collectedBetween($previousMonthStart, $monthStart),
            ],
            'unpaid' => [
                'count' => (clone $openInvoices)->count(),
                'total' => (int) (clone $openInvoices)->sum('total'),
                'overdue_count' => (clone $overdue)->count(),
                'overdue_total' => (int) (clone $overdue)->sum('total'),
            ],
            'transfers_to_review' => SaasPayment::query()->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [SaasPayment::MANUAL_REVIEW, SaasPayment::REQUIRES_REVIEW])->count(),
            'trials_ending' => array_slice($trialsEnding, 0, 10),
            'overdue_invoices' => (clone $overdue)->orderBy('due_on')->limit(10)->get()
                ->map(fn (SaasInvoice $i): array => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'tenant' => $tenantNames[$i->tenant_id] ?? '?',
                    'total' => $i->total,
                    'due_on' => $i->due_on->toDateString(),
                ])->all(),
            'history' => $this->history($now, $firstPayments),
        ];
    }

    /** Valeur mensuelle d'un abonnement actif (annuel ramené au mois), null si le tarif n'est pas fixé. */
    private function monthlyValue(Subscription $subscription): ?int
    {
        $plan = $subscription->plan;

        if ($subscription->billing_cycle === 'yearly' && $plan->price_yearly !== null) {
            return intdiv($plan->price_yearly, 12);
        }

        return $plan->price_monthly;
    }

    /**
     * Date du premier paiement de chaque entreprise (conversion essai → payant).
     *
     * @return Collection<string, CarbonImmutable>
     */
    private function firstPaymentByTenant(): Collection
    {
        return SaasInvoice::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', SaasInvoice::PAID)->whereNotNull('paid_at')
            ->selectRaw('tenant_id, min(paid_at) as first_paid_at')
            ->groupBy('tenant_id')
            ->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->tenant_id => CarbonImmutable::parse((string) $row->first_paid_at)]);
    }

    /** Abonnements passés d'actif à inactif (non-renouvellement ou suspension) depuis la date donnée. */
    private function churnedSince(CarbonImmutable $since): int
    {
        return AuditEvent::query()
            ->whereIn('action', ['subscription.status_changed', 'subscription.suspended'])
            ->where('created_at', '>=', $since)
            ->where('before->status', Subscription::ACTIVE)
            ->whereNotNull('tenant_id')
            ->distinct()
            ->count('tenant_id');
    }

    private function collectedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) SaasInvoice::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', SaasInvoice::PAID)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->sum('total');
    }

    /**
     * Douze derniers mois : encaissements, nouvelles entreprises, conversions.
     *
     * @param  Collection<string, CarbonImmutable>  $firstPayments
     * @return list<array{month: string, collected: int, new_tenants: int, conversions: int}>
     */
    private function history(CarbonImmutable $now, Collection $firstPayments): array
    {
        $from = $now->startOfMonth()->subMonths(11);

        $collected = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', SaasInvoice::PAID)->where('paid_at', '>=', $from)
            ->get(['paid_at', 'total'])
            ->groupBy(fn (SaasInvoice $i): string => $i->paid_at?->format('Y-m') ?? '')
            ->map(fn (Collection $rows): int => (int) $rows->sum('total'));

        $tenants = Tenant::query()->where('created_at', '>=', $from)->get(['created_at'])
            ->countBy(fn (Tenant $t): string => $t->created_at?->format('Y-m') ?? '');

        $conversions = $firstPayments->filter(fn (CarbonImmutable $at) => $at->gte($from))
            ->countBy(fn (CarbonImmutable $at): string => $at->format('Y-m'));

        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $key = $from->addMonths($i)->format('Y-m');
            $months[] = [
                'month' => $key,
                'collected' => (int) ($collected[$key] ?? 0),
                'new_tenants' => (int) ($tenants[$key] ?? 0),
                'conversions' => (int) ($conversions[$key] ?? 0),
            ];
        }

        return $months;
    }
}
