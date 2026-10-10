<?php

namespace App\Domain\Subscriptions;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rappels de fin d'essai J-3, J-1 et J0 aux administrateurs de l'entreprise.
 *
 * Idempotent : chaque rappel est d'abord enregistré (unicité subscription/kind),
 * puis envoyé seulement si l'enregistrement vient d'être créé. Si plusieurs
 * rappels sont échus (tâche en retard), seul le plus proche de l'échéance part.
 */
final class SendTrialNotices
{
    /**
     * @return int nombre de rappels envoyés
     */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        /** @var list<int> $days */
        $days = config('diaba.subscriptions.trial_notice_days');
        rsort($days);
        $sent = 0;

        if ($days === []) {
            return 0;
        }

        $subscriptions = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', [Subscription::TRIALING, Subscription::READ_ONLY])
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now->addDays(max($days)))
            ->where('trial_ends_at', '>', $now->subDays(2))
            ->get();

        foreach ($subscriptions as $subscription) {
            /** @var CarbonImmutable $endsAt */
            $endsAt = $subscription->trial_ends_at;
            $due = array_values(array_filter($days, fn (int $d) => $now->gte($endsAt->subDays($d))));

            if ($due === []) {
                continue;
            }

            $closest = min($due);

            // Le rappel le plus proche n'est envoyé que si c'est nous qui venons de l'enregistrer.
            $isNew = $this->record($subscription->id, $closest, $now);

            // Les rappels plus anciens, devenus inutiles, sont marqués pour ne jamais partir en retard.
            foreach (array_diff($due, [$closest]) as $older) {
                $this->record($subscription->id, $older, $now);
            }

            if (! $isNew) {
                continue;
            }

            $tenant = Tenant::query()->find($subscription->tenant_id);

            foreach ($this->admins($subscription->tenant_id) as $admin) {
                $admin->notify(new TrialEndingNotification((string) $tenant?->display_name, $closest, $endsAt));
            }

            $sent++;
        }

        return $sent;
    }

    private function record(string $subscriptionId, int $days, CarbonImmutable $now): bool
    {
        return DB::table('subscription_notices')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'subscription_id' => $subscriptionId,
            'kind' => "trial_d{$days}",
            'sent_at' => $now,
        ]) === 1;
    }

    /**
     * @return iterable<User>
     */
    private function admins(string $tenantId): iterable
    {
        return TenantMembership::query()
            ->with('user', 'role')
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->get()
            ->filter(fn (TenantMembership $m) => $m->role->code === PermissionCatalog::ADMIN_ROLE && $m->user->isActive())
            ->map(fn (TenantMembership $m) => $m->user);
    }
}
