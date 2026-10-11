<?php

namespace App\Http\Controllers;

use App\Domain\Billing\ProviderRegistry;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « Abonnement » : toujours accessible, même suspendu, pour régulariser :
 * choix de formule et d'options, facture, paiement (prestataire ou virement).
 */
class SubscriptionController extends Controller
{
    public function show(TenantContext $context, FeatureAccess $access, ProviderRegistry $providers): Response
    {
        $tenant = $context->tenant();
        $canManage = Gate::allows('organization.manage');

        return Inertia::render('settings/Subscription', [
            'subscription' => $access->summary($tenant),
            'limits' => [
                'warehouses' => [
                    'usage' => $access->usage($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
                    'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
                ],
                'users' => [
                    'usage' => $access->usage($tenant, FeatureAccess::RESOURCE_USERS),
                    'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_USERS),
                ],
            ],
            'plans' => Plan::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get(['code', 'name', 'warehouse_limit', 'included_users', 'accounting_enabled', 'price_monthly', 'price_yearly']),
            'addons' => collect((array) config('diaba.addons'))
                ->filter(fn ($a) => is_array($a) && ($a['price_monthly'] !== null || $a['price_yearly'] !== null))
                ->map(fn (array $a, string $code) => ['code' => $code, 'label' => $a['label'], 'price_monthly' => $a['price_monthly'], 'price_yearly' => $a['price_yearly']])
                ->values(),
            'invoices' => $canManage ? SaasInvoice::query()->with('plan:id,name')->latest()->limit(12)->get()
                ->map(fn (SaasInvoice $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'plan' => $i->plan->name,
                    'cycle' => $i->billing_cycle,
                    'lines' => $i->lines,
                    'total' => $i->total,
                    'status' => $i->status,
                    'due_on' => $i->due_on->toDateString(),
                    'paid_at' => $i->paid_at?->toIso8601String(),
                    'period_end' => $i->period_end?->toIso8601String(),
                    'payments' => SaasPayment::query()->where('invoice_id', $i->id)->latest()->get()
                        ->map(fn (SaasPayment $p) => ['provider' => $p->provider, 'status' => $p->status, 'reference' => $p->external_reference, 'amount' => $p->amount])
                        ->values(),
                ]) : [],
            'providers' => $providers->available(),
            'transferInstructions' => config('billing.transfer_instructions'),
            'can' => ['manage' => $canManage],
        ]);
    }
}
