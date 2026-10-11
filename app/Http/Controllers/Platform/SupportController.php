<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\InvalidBillingOperation;
use App\Domain\Subscriptions\FeatureAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SupportGrant;
use App\Models\SyncCommand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistance (docs/13) : le super-administrateur n'est jamais membre d'une
 * entreprise. Un accès support est ponctuel, motivé, limité dans le temps,
 * révocable, en lecture seule et visible dans l'audit de l'entreprise.
 */
class SupportController extends Controller
{
    public function grant(Request $request, Tenant $tenant, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.support_access');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'minutes' => ['required', 'integer', 'min:15', 'max:240'],
        ], attributes: ['reason' => 'motif', 'minutes' => 'durée']);

        /** @var User $user */
        $user = $request->user();
        $grant = SupportGrant::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'reason' => trim((string) $data['reason']),
            'expires_at' => now()->addMinutes((int) $data['minutes']),
        ]);

        $audit->record('support.access_granted', $grant, after: ['expires_at' => $grant->expires_at->toIso8601String()],
            metadata: ['reason' => $grant->reason], actor: $user, tenant: $tenant);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.support_granted', ['time' => $grant->expires_at->format('H:i')])]);

        return to_route('platform.support.show', $tenant);
    }

    public function revoke(Request $request, Tenant $tenant, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('platform.support_access');

        /** @var User $user */
        $user = $request->user();
        $grant = $this->activeGrant($tenant, $user);
        $grant->revoked_at = now()->toImmutable();
        $grant->save();

        $audit->record('support.access_revoked', $grant, actor: $user, tenant: $tenant);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.support_revoked')]);

        return to_route('platform.tenants.index');
    }

    public function show(Request $request, Tenant $tenant, FeatureAccess $access, AuditLog $audit): Response
    {
        Gate::authorize('platform.support_access');

        /** @var User $user */
        $user = $request->user();
        $grant = $this->activeGrant($tenant, $user);
        $audit->record('support.viewed', $grant, actor: $user, tenant: $tenant);

        return Inertia::render('platform/Support', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->display_name, 'legal_name' => $tenant->legal_name, 'status' => $tenant->status],
            'grant' => ['reason' => $grant->reason, 'expires_at' => $grant->expires_at->toIso8601String()],
            'subscription' => $access->summary($tenant),
            'members' => TenantMembership::query()->with(['user:id,name,email', 'role:id,name'])->where('tenant_id', $tenant->id)->get()
                ->map(fn (TenantMembership $m) => ['name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name, 'status' => $m->status]),
            'warehouses' => Warehouse::query()->forTenant($tenant)->orderBy('name')->get(['code', 'name', 'status']),
            'audit' => AuditEvent::query()->with('actor:id,name')->where('tenant_id', $tenant->id)->latest('created_at')->limit(30)->get()
                ->map(fn (AuditEvent $e) => ['action' => $e->action, 'actor' => $e->actor?->name, 'created_at' => $e->created_at->toIso8601String()]),
            'sync_issues' => SyncCommand::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', '<>', SyncCommand::ACCEPTED)
                ->latest('created_at')->limit(20)->get(['status', 'error_code', 'error_message', 'created_at']),
        ]);
    }

    private function activeGrant(Tenant $tenant, User $user): SupportGrant
    {
        /** @var SupportGrant|null $grant */
        $grant = SupportGrant::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest('created_at')->first();

        return $grant ?? throw new InvalidBillingOperation(__('billing.errors.support_inactive'));
    }
}
