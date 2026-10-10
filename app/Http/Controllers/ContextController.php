<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WarehouseAccess;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changement d'entreprise ou de dépôt actif. La cible n'est acceptée que si
 * l'utilisateur y a réellement accès ; sinon 404, pour ne pas révéler son existence.
 */
class ContextController extends Controller
{
    public function switchTenant(Request $request): RedirectResponse
    {
        $tenantId = (string) $request->validate(['tenant_id' => ['required', 'uuid']])['tenant_id'];

        /** @var User $user */
        $user = $request->user();

        $allowed = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'))
            ->exists();

        abort_unless($allowed, 404);

        $request->session()->put(ResolveTenantContext::SESSION_TENANT, $tenantId);
        $request->session()->forget(ResolveTenantContext::SESSION_WAREHOUSE);

        return redirect()->route('dashboard');
    }

    public function switchWarehouse(Request $request, TenantContext $context, WarehouseAccess $access): RedirectResponse
    {
        $warehouseId = (string) $request->validate(['warehouse_id' => ['required', 'uuid']])['warehouse_id'];

        $membership = $context->membership();
        abort_if($membership === null || $access->find($membership, $warehouseId) === null, 404);

        $request->session()->put(ResolveTenantContext::SESSION_WAREHOUSE, $warehouseId);

        return back();
    }
}
