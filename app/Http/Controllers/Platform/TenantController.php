<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Console plateforme : entreprises et abonnements.
 *
 * Le super-administrateur ne devient jamais membre d'une entreprise : il ne voit
 * ici que des informations d'abonnement, jamais les données métier. Chaque action
 * manuelle exige un motif et est auditée dans le journal de l'entreprise.
 */
class TenantController extends Controller
{
    private const array STATUSES = [Subscription::TRIALING, Subscription::ACTIVE, Subscription::READ_ONLY, Subscription::SUSPENDED, Subscription::CANCELLED];

    public function index(Request $request, FeatureAccess $access): Response
    {
        Gate::authorize('platform.tenants.manage');

        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '';
        $plan = trim((string) $request->query('plan', ''));

        $subscriptions = fn () => Subscription::query()->withoutGlobalScope(TenantScope::class)->select('tenant_id');

        $tenants = Tenant::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('legal_name', 'ilike', "%{$search}%")))
            ->when($status !== '', fn ($q) => $q->whereIn('id', $subscriptions()->effectiveStatus($status)))
            ->when($plan !== '', fn ($q) => $q->whereIn('id', $subscriptions()
                ->where('status', '<>', Subscription::CANCELLED)
                ->whereIn('plan_id', Plan::query()->where('code', $plan)->select('id'))))
            ->withCount(['memberships' => fn ($q) => $q->where('status', 'active')])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(function (Tenant $tenant) use ($access) {
                $summary = $access->summary($tenant);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->display_name,
                    'legal_name' => $tenant->legal_name,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                    'members' => $tenant->memberships_count,
                    'subscription' => $summary,
                    'subscription_id' => $access->subscription($tenant)?->id,
                ];
            });

        return Inertia::render('platform/Tenants', [
            'tenants' => $tenants,
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'active']),
            'filters' => ['q' => $search, 'status' => $status, 'plan' => $plan],
            'statuses' => self::STATUSES,
            'canManageBilling' => Gate::allows('platform.billing.manage'),
        ]);
    }

    /**
     * Fiche client : identité, contacts de facturation (administrateurs), usage
     * par rapport à la formule, factures, paiements et historique de l'abonnement.
     * Aucune donnée métier (ventes, stock, clients de l'entreprise).
     */
    public function show(Tenant $tenant, FeatureAccess $access): Response
    {
        Gate::authorize('platform.tenants.manage');

        $subscription = $access->subscription($tenant);
        $memberships = TenantMembership::query()->with(['user:id,name,email,last_login_at', 'role:id,code,name'])
            ->where('tenant_id', $tenant->id)->get();
        $invoices = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->with('plan:id,name')
            ->where('tenant_id', $tenant->id)->latest()->limit(50)->get();
        $lastLogin = $memberships->map(fn (TenantMembership $m) => $m->user->last_login_at)->filter()->max();

        return Inertia::render('platform/TenantShow', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->display_name,
                'legal_name' => $tenant->legal_name,
                'country' => $tenant->country_code,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ],
            'subscription' => $access->summary($tenant),
            'subscription_id' => $subscription?->id,
            'period' => $subscription === null ? null : [
                'billing_cycle' => $subscription->billing_cycle,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            'usage' => [
                'users' => $memberships->where('status', 'active')->count(),
                'included_users' => $subscription?->plan->included_users,
                'warehouses' => Warehouse::query()->forTenant($tenant)->where('status', 'active')->count(),
                'warehouse_limit' => $subscription?->plan->warehouse_limit,
                'last_login_at' => $lastLogin?->toIso8601String(),
            ],
            // Contacts utiles à la facturation et aux relances : administrateurs actifs uniquement.
            'contacts' => $memberships
                ->filter(fn (TenantMembership $m) => $m->status === 'active' && $m->role->code === PermissionCatalog::ADMIN_ROLE)
                ->map(fn (TenantMembership $m) => ['name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name])
                ->values(),
            'invoices' => $invoices->map(fn (SaasInvoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'plan' => $i->plan->name, 'cycle' => $i->billing_cycle,
                'total' => $i->total, 'status' => $i->status, 'due_on' => $i->due_on->toDateString(),
                'paid_at' => $i->paid_at?->toIso8601String(),
            ]),
            'payments' => SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
                ->latest()->limit(50)->get()
                ->map(fn (SaasPayment $p) => [
                    'id' => $p->id, 'provider' => $p->provider, 'status' => $p->status, 'amount' => $p->amount,
                    'reference' => $p->external_reference, 'proof' => $p->proof_reference,
                    'created_at' => $p->created_at?->toIso8601String(),
                ]),
            'history' => AuditEvent::query()->with('actor:id,name')->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('action', 'like', 'subscription.%')->orWhere('action', 'like', 'support.access_%'))
                ->latest('created_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (AuditEvent $e) => [
                    'id' => $e->id,
                    'action' => $e->action,
                    'actor' => $e->actor?->name,
                    'from' => $e->before['status'] ?? null,
                    'to' => $e->after['status'] ?? null,
                    'plan' => $e->after['plan'] ?? null,
                    'reason' => $e->metadata['reason'] ?? null,
                    'created_at' => $e->created_at->toIso8601String(),
                ]),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'active']),
            'canManageBilling' => Gate::allows('platform.billing.manage'),
            'canSupport' => Gate::allows('platform.support_access'),
        ]);
    }

    public function updateSubscription(Request $request, Subscription $platformSubscription, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');

        $data = $request->validate([
            'action' => ['required', Rule::in(['change_plan', 'extend_trial', 'activate', 'suspend'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'plan_id' => ['required_if:action,change_plan', 'nullable', 'uuid', Rule::exists('plans', 'id')],
            'days' => ['required_if:action,extend_trial', 'nullable', 'integer', 'min:1', 'max:60'],
            'billing_cycle' => ['required_if:action,activate', 'nullable', Rule::in(['monthly', 'yearly'])],
        ], attributes: [
            'reason' => __('platform.fields.reason'),
            'plan_id' => __('platform.fields.plan'),
            'days' => __('platform.fields.days'),
            'billing_cycle' => __('platform.fields.billing_cycle'),
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $reason = (string) $data['reason'];

        match ((string) $data['action']) {
            'change_plan' => $lifecycle->changePlan($platformSubscription, Plan::query()->whereKey((string) $data['plan_id'])->firstOrFail(), $reason, $actor),
            'extend_trial' => $lifecycle->extendTrial($platformSubscription, (int) $data['days'], $reason, $actor),
            'activate' => $lifecycle->activate($platformSubscription, (string) $data['billing_cycle'], $reason, $actor),
            'suspend' => $lifecycle->suspend($platformSubscription, $reason, $actor),
            default => abort(422),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => __('platform.subscription_updated')]);

        return back(303);
    }

    /**
     * Résolution de la liaison {subscription} hors contexte entreprise (accès plateforme explicite).
     */
    public static function resolveSubscription(string $value): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->findOrFail($value);
    }
}
