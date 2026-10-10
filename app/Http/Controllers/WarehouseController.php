<?php

namespace App\Http\Controllers;

use App\Domain\Organization\CreateWarehouse;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(TenantContext $context, FeatureAccess $access): Response
    {
        Gate::authorize('warehouse.view');
        $tenant = $context->tenant();

        return Inertia::render('settings/Warehouses', [
            'warehouses' => Warehouse::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'address', 'status']),
            'usage' => $access->usage($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
            'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
            'canManage' => Gate::allows('warehouse.manage'),
        ]);
    }

    public function store(Request $request, TenantContext $context, CreateWarehouse $createWarehouse): RedirectResponse
    {
        Gate::authorize('warehouse.manage');

        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        /** @var array{code: string, name: string, address?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,15}$/',
                Rule::unique('warehouses', 'code')->where('tenant_id', $context->tenantId()),
            ],
            'address' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'name' => __('onboarding.fields.warehouse_name'),
            'code' => __('onboarding.fields.warehouse_code'),
            'address' => __('onboarding.fields.warehouse_address'),
        ]);

        $warehouse = $createWarehouse($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.warehouse_created', ['name' => $warehouse->name])]);

        return to_route('warehouses.index');
    }
}
