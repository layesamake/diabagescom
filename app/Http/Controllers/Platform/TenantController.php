<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\CompanyProfile;
use App\Domain\Platform\ProvisionPlatformTenant;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionClock;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreatePlatformTenantRequest;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantProfile;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function create(): Response
    {
        Gate::authorize('platform.tenants.manage');

        return Inertia::render('platform/TenantCreate', [
            'countries' => [['code' => 'SN', 'name' => __('onboarding.countries.SN')]],
            'plans' => Plan::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get(['code', 'name', 'warehouse_limit', 'included_users', 'accounting_enabled']),
            'trialDays' => (int) config('diaba.subscriptions.trial_days'),
            'defaults' => [
                'country_code' => 'SN',
                'timezone' => 'Africa/Dakar',
                'warehouse_code' => 'PRINCIPAL',
                'plan_code' => config('diaba.subscriptions.default_plan'),
            ],
        ]);
    }

    public function store(CreatePlatformTenantRequest $request, ProvisionPlatformTenant $provision): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{owner_name: string, owner_email: string, legal_name: string, display_name: string, country_code: string, timezone: string, warehouse_name: string, warehouse_code: string, warehouse_address?: string|null, plan_code: string} $data */
        $data = $request->validated();
        $membership = $provision($actor, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('platform.tenant_created', ['email' => $membership->user->email])]);

        return redirect()->route('platform.tenants.show', $membership->tenant_id);
    }

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
                $subscription = $this->platformSubscription($tenant);
                $summary = $subscription === null ? $access->summary($tenant) : $this->platformSummary($subscription);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->display_name,
                    'legal_name' => $tenant->legal_name,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                    'members' => $tenant->memberships_count,
                    'subscription' => $summary,
                    'subscription_id' => $subscription?->id,
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

        $subscription = $this->platformSubscription($tenant);
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
            'subscription' => $subscription === null ? $access->summary($tenant) : $this->platformSummary($subscription),
            'subscription_id' => $subscription?->id,
            'period' => $subscription === null ? null : [
                'billing_cycle' => $subscription->billing_cycle,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at' => $subscription->cancel_at?->toIso8601String(),
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
                ->filter(fn (TenantMembership $m) => $m->role->code === PermissionCatalog::ADMIN_ROLE)
                ->map(fn (TenantMembership $m) => ['id' => $m->id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name, 'status' => $m->status, 'pending' => $m->user->email_verified_at === null])
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
            'profile' => $this->profileData($tenant),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'active']),
            'canManageBilling' => Gate::allows('platform.billing.manage'),
            'canSupport' => Gate::allows('platform.support_access'),
        ]);
    }

    /**
     * Fiche de l'entreprise vue par le support : logo et devanture affichables ;
     * le cachet/signature n'est jamais exposé (présence seulement).
     *
     * @return array<string, mixed>
     */
    private function profileData(Tenant $tenant): array
    {
        $profile = app(CompanyProfile::class)->of($tenant);
        $signed = fn (string $slot, ?string $path): ?string => CatalogImages::url('platform.tenants.profile.images.show', $path, ['tenant' => $tenant->id, 'slot' => $slot], $profile->version);

        return [
            'vat_regime' => $profile->vat_regime,
            'maps_url' => $profile->maps_url,
            'logo_url' => $signed('logo', $profile->logo_path),
            'cover_url' => $signed('cover', $profile->cover_path),
            'has_stamp' => $profile->stamp_path !== null,
            'regimes' => TenantProfile::VAT_REGIMES,
        ];
    }

    public function resendInvitation(Request $request, Tenant $tenant, TenantMembership $membership, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        $this->ensureMembershipBelongsTo($tenant, $membership);

        $user = $membership->user;
        /** @var User $actor */
        $actor = $request->user();
        $pending = $user->email_verified_at === null;
        $link = $pending
            ? route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email])
            : route('login');
        $user->notify(new TeamInvitationNotification($tenant->display_name, $actor->name, $membership->role->name, $link, $pending));
        $audit->record('membership.invitation_sent', $membership, actor: $actor, tenant: $tenant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.resent', ['email' => $user->email])]);

        return back(303);
    }

    public function suspendMember(Request $request, Tenant $tenant, TenantMembership $membership, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        $this->ensureMembershipBelongsTo($tenant, $membership);
        /** @var User $actor */
        $actor = $request->user();

        if ($membership->status !== TenantMembership::STATUS_ACTIVE) {
            return back(303);
        }

        if ($membership->isAdmin() && $this->activeAdminCount($tenant) <= 1) {
            throw ValidationException::withMessages(['member' => __('organization.team.errors.last_admin')]);
        }

        DB::transaction(function () use ($membership, $tenant, $actor, $audit): void {
            $membership->status = TenantMembership::STATUS_SUSPENDED;
            $membership->save();
            $audit->record('membership.suspended', $membership, before: ['status' => TenantMembership::STATUS_ACTIVE], after: ['status' => TenantMembership::STATUS_SUSPENDED], actor: $actor, tenant: $tenant);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.suspended', ['name' => $membership->user->name])]);

        return back(303);
    }

    public function reactivateMember(Request $request, Tenant $tenant, TenantMembership $membership, FeatureAccess $access, SubscriptionLifecycle $lifecycle, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        $this->ensureMembershipBelongsTo($tenant, $membership);
        /** @var User $actor */
        $actor = $request->user();

        if ($membership->status !== TenantMembership::STATUS_SUSPENDED) {
            return back(303);
        }

        DB::transaction(function () use ($membership, $tenant, $actor, $access, $lifecycle, $audit): void {
            $lifecycle->lockForTenant($tenant);
            $access->forget($tenant);
            $access->assertCanAdd($tenant, FeatureAccess::RESOURCE_USERS);
            $membership->status = TenantMembership::STATUS_ACTIVE;
            $membership->save();
            $audit->record('membership.reactivated', $membership, before: ['status' => TenantMembership::STATUS_SUSPENDED], after: ['status' => TenantMembership::STATUS_ACTIVE], actor: $actor, tenant: $tenant);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.reactivated', ['name' => $membership->user->name])]);

        return back(303);
    }

    public function updateSubscription(Request $request, Subscription $platformSubscription, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');

        $data = $request->validate([
            'action' => ['required', Rule::in(['change_plan', 'extend_trial', 'activate', 'suspend', 'cancel_now', 'cancel_at_period_end', 'reactivate'])],
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
            'cancel_now' => $lifecycle->cancel($platformSubscription, false, $reason, $actor),
            'cancel_at_period_end' => $lifecycle->cancel($platformSubscription, true, $reason, $actor),
            'reactivate' => $lifecycle->reactivate($platformSubscription, $reason, $actor),
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

    private function platformSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->with('plan')
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")
            ->latest()
            ->first();
    }

    /** @return array<string, mixed> */
    private function platformSummary(Subscription $subscription): array
    {
        $now = now()->toImmutable();
        $effective = SubscriptionClock::effective($subscription, $now);

        return [
            'status' => $effective['status'],
            'plan' => ['code' => $subscription->plan->code, 'name' => $subscription->plan->name],
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'cancel_at' => $subscription->cancel_at?->toIso8601String(),
            'read_only_ends_at' => $effective['read_only_ends_at']?->toIso8601String(),
            'trial_days_left' => $subscription->trial_ends_at !== null && $effective['status'] === Subscription::TRIALING
                ? max(0, (int) ceil($now->diffInHours($subscription->trial_ends_at, false) / 24))
                : null,
            'can_write' => in_array($effective['status'], [Subscription::TRIALING, Subscription::ACTIVE], true),
            'features' => [
                'accounting' => $effective['status'] !== Subscription::CANCELLED && $subscription->plan->accounting_enabled,
                'online_store' => $effective['status'] !== Subscription::CANCELLED && $subscription->plan->online_store_enabled,
            ],
        ];
    }

    private function ensureMembershipBelongsTo(Tenant $tenant, TenantMembership $membership): void
    {
        abort_unless($membership->tenant_id === $tenant->id, 404);
    }

    private function activeAdminCount(Tenant $tenant): int
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role_id', Role::system(PermissionCatalog::SCOPE_TENANT, PermissionCatalog::ADMIN_ROLE)->id)
            ->count();
    }
}
