<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\CreateTenant;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Requests\Onboarding\CreateCompanyRequest;
use App\Models\Plan;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistant de démarrage : création de l'entreprise, du premier administrateur
 * (l'utilisateur connecté) et du premier dépôt.
 */
class OnboardingController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->memberships()->where('status', 'active')->exists() && ! $request->boolean('new')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('onboarding/CreateCompany', [
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

    public function store(CreateCompanyRequest $request, CreateTenant $createTenant): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{legal_name: string, display_name: string, country_code: string, timezone: string, warehouse_name: string, warehouse_code: string, warehouse_address?: string|null, plan_code: string} $data */
        $data = $request->validated();
        $membership = $createTenant($user, $data);

        $warehouseId = Warehouse::query()->forTenant($membership->tenant)->value('id');

        $request->session()->put(ResolveTenantContext::SESSION_TENANT, $membership->tenant_id);
        $request->session()->put(ResolveTenantContext::SESSION_WAREHOUSE, $warehouseId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('onboarding.created', ['name' => $membership->tenant->display_name])]);

        return redirect()->route('dashboard');
    }
}
