<?php

namespace App\Domain\Billing;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantScope;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Notifications\SaasInvoiceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Relances et renouvellements (planifiés chaque jour, idempotents) :
 * - facture de renouvellement émise avant la fin de période d'un abonnement payé ;
 * - rappels avant échéance, à l'échéance et après, une seule fois par étape.
 */
final class BillingReminders
{
    public function __construct(private readonly SaasBilling $billing) {}

    /**
     * @return array{renewals: int, reminders: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return ['renewals' => $this->renewals($now), 'reminders' => $this->reminders($now)];
    }

    private function renewals(CarbonImmutable $now): int
    {
        $count = 0;
        $horizon = $now->addDays((int) config('billing.renewal_days_before', 7));

        $subscriptions = Subscription::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', Subscription::ACTIVE)
            ->whereNotNull('billing_cycle')
            ->whereBetween('current_period_end', [$now, $horizon])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('saas_invoices')
                ->whereColumn('saas_invoices.tenant_id', 'subscriptions.tenant_id')
                ->where(fn ($w) => $w->where('saas_invoices.status', SaasInvoice::OPEN)
                    ->orWhereColumn('saas_invoices.period_start', '>=', 'subscriptions.current_period_end')))
            ->get();

        foreach ($subscriptions as $subscription) {
            /** @var Plan $plan */
            $plan = Plan::query()->findOrFail($subscription->plan_id);
            $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

            try {
                $invoice = $this->billing->createInvoice($tenant, $plan, (string) $subscription->billing_cycle, [], null);
                $this->notify($invoice, 'issued');
                $count++;
            } catch (InvalidBillingOperation) {
                // Tarif non fixé ou facture déjà ouverte : rien à émettre.
            }
        }

        return $count;
    }

    private function reminders(CarbonImmutable $now): int
    {
        $count = 0;
        $stages = (array) config('billing.reminders', [-3, 0, 3]);

        foreach (SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('status', SaasInvoice::OPEN)->get() as $invoice) {
            $daysFromDue = (int) $invoice->due_on->startOfDay()->diffInDays($now->startOfDay(), false);
            $reached = count(array_filter($stages, fn ($offset) => $daysFromDue >= (int) $offset));

            if ($reached <= $invoice->reminder_stage) {
                continue;
            }

            // Passage d'étape atomique : un passage concurrent n'envoie pas deux fois.
            $updated = DB::table('saas_invoices')->where('id', $invoice->id)->where('reminder_stage', $invoice->reminder_stage)
                ->update(['reminder_stage' => $reached, 'updated_at' => now()]);

            if ($updated === 1) {
                $this->notify($invoice, $daysFromDue < 0 ? 'due_soon' : ($daysFromDue === 0 ? 'due' : 'overdue'));
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  'issued'|'due_soon'|'due'|'overdue'  $stage
     */
    private function notify(SaasInvoice $invoice, string $stage): void
    {
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        $notification = new SaasInvoiceNotification($tenant->display_name, $invoice->number, $invoice->total, $invoice->due_on->format('d/m/Y'), $stage);

        TenantMembership::query()->with('user', 'role')
            ->where('tenant_id', $tenant->id)->where('status', TenantMembership::STATUS_ACTIVE)->get()
            ->filter(fn (TenantMembership $m) => $m->role->code === PermissionCatalog::ADMIN_ROLE && $m->user->isActive())
            ->each(fn (TenantMembership $m) => $m->user->notify($notification));
    }
}
