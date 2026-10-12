<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Console plateforme : formules (limites, prix, activation). Modifications auditées.
 * Les prix sont des entiers XOF ; vide = tarif non encore validé.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('platform.plans.manage');

        return Inertia::render('platform/Plans', [
            'plans' => Plan::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.plans.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'warehouse_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'included_users' => ['required', 'integer', 'min:1', 'max:10000'],
            'accounting_enabled' => ['required', 'boolean'],
            'online_store_enabled' => ['sometimes', 'boolean'],
            'price_monthly' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_yearly' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: [
            'name' => __('platform.plan_fields.name'),
            'warehouse_limit' => __('platform.plan_fields.warehouse_limit'),
            'included_users' => __('platform.plan_fields.included_users'),
            'price_monthly' => __('platform.plan_fields.price_monthly'),
            'price_yearly' => __('platform.plan_fields.price_yearly'),
            'reason' => __('platform.fields.reason'),
        ]);

        DB::transaction(function () use ($plan, $data, $audit): void {
            $before = $plan->only(array_keys($data));
            $plan->fill(Arr::except($data, ['reason']))->save();
            $audit->record('platform.plan_updated', $plan, before: $before, after: $plan->only(array_keys($data)), metadata: ['reason' => $data['reason']]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('platform.plan_updated', ['name' => $plan->name])]);

        return back(303);
    }
}
