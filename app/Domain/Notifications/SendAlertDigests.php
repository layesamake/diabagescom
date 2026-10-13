<?php

namespace App\Domain\Notifications;

use App\Domain\Tenancy\TenantContext;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Notifications\AlertDigestNotification;
use Illuminate\Support\Facades\DB;

/**
 * Résumé quotidien par e-mail des points à traiter, pour les membres qui l'ont
 * demandé. Une seule fois par jour, utilisateur et entreprise (rejeu sans
 * doublon) ; rien n'est envoyé s'il n'y a rien à traiter. Le message ne
 * contient que des compteurs, jamais de noms ni de montants.
 */
final class SendAlertDigests
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AlertCenter $alerts,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * @return int nombre de résumés envoyés
     */
    public function __invoke(): int
    {
        $sent = 0;
        $today = now()->toDateString();

        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->whereIn('id', Subscription::query()->withoutGlobalScopes()
                ->whereIn('status', [Subscription::TRIALING, Subscription::ACTIVE, Subscription::READ_ONLY])->select('tenant_id'))
            ->get();

        foreach ($tenants as $tenant) {
            // Une requête par entreprise : seuls les membres ayant activé le résumé e-mail sont examinés.
            $wanting = DB::table('notification_preferences')->where('tenant_id', $tenant->id)->where('email', true)->distinct()->pluck('user_id')->all();

            if ($wanting === []) {
                continue;
            }

            $memberships = TenantMembership::query()->with(['user', 'role', 'tenant'])
                ->where('tenant_id', $tenant->id)->where('status', TenantMembership::STATUS_ACTIVE)->whereIn('user_id', $wanting)->get();

            foreach ($memberships as $membership) {
                if (! $membership->user->isActive()) {
                    continue;
                }

                $counts = $this->countsFor($tenant, $membership);

                // Le registre garantit au plus un résumé par jour, même si la tâche est rejouée.
                if ($counts === [] || ! $this->claim($tenant->id, $membership->user_id, $today)) {
                    continue;
                }

                $membership->user->notify(new AlertDigestNotification($tenant->display_name, $counts));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return array<string, int>
     */
    private function countsFor(Tenant $tenant, TenantMembership $membership): array
    {
        $before = [$this->context->tenantOrNull(), $this->context->membership(), $this->context->warehouse()];
        $this->context->set($tenant, $membership);

        try {
            $user = $membership->user;
            $counts = AlertCenter::counts($this->alerts->pending($user));
            $wanted = array_keys(array_filter($this->preferences->for($user, array_keys($counts)), fn (array $p) => $p['email']));

            return array_intersect_key($counts, array_flip($wanted));
        } finally {
            $before[0] !== null ? $this->context->set($before[0], $before[1], $before[2]) : $this->context->clear();
        }
    }

    private function claim(string $tenantId, string $userId, string $day): bool
    {
        return DB::table('alert_digests')->insertOrIgnore(['tenant_id' => $tenantId, 'user_id' => $userId, 'sent_on' => $day]) === 1;
    }
}
