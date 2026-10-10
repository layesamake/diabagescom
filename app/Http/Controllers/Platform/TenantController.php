<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
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
    public function index(Request $request, FeatureAccess $access): Response
    {
        Gate::authorize('platform.tenants.manage');

        $search = trim((string) $request->query('q', ''));

        $tenants = Tenant::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('legal_name', 'ilike', "%{$search}%")))
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
            'filters' => ['q' => $search],
            'canManageBilling' => Gate::allows('platform.billing.manage'),
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
