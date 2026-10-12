<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\TeamMembers;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Collaborateurs : les personnes qui utilisent l'application
 * avec l'administrateur, leur rôle et les dépôts auxquels elles ont accès.
 */
class MemberController extends Controller
{
    public function index(Request $request, TenantContext $context, FeatureAccess $access): Response
    {
        Gate::authorize('member.view');
        $tenant = $context->tenant();

        $warehouses = DB::table('warehouse_memberships as wm')->join('warehouses as w', 'w.id', '=', 'wm.warehouse_id')
            ->where('wm.tenant_id', $tenant->id)->get(['wm.membership_id', 'w.id', 'w.name'])->groupBy('membership_id');

        $members = TenantMembership::query()->with(['user:id,name,email,email_verified_at,two_factor_confirmed_at', 'role:id,code'])
            ->where('tenant_id', $tenant->id)->whereIn('status', [TenantMembership::STATUS_ACTIVE, TenantMembership::STATUS_SUSPENDED])
            ->get()
            ->sortBy(fn (TenantMembership $m) => [$m->status !== TenantMembership::STATUS_ACTIVE, mb_strtolower($m->user->name)])
            ->values()
            ->map(fn (TenantMembership $m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->code,
                'status' => $m->status,
                'pending' => $m->user->email_verified_at === null,
                'two_factor' => $m->user->two_factor_confirmed_at !== null,
                'warehouses' => ($warehouses[$m->id] ?? collect())->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values(),
                'is_me' => $m->user_id === $request->user()?->id,
            ]);

        return Inertia::render('settings/Members', [
            'members' => $members,
            'roles' => collect(PermissionCatalog::tenantRoles())->map(fn (array $r, string $code) => ['code' => $code, 'name' => $r['name']])->values(),
            'warehouses' => Warehouse::query()->where('status', Warehouse::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'seats' => ['used' => $access->usage($tenant, FeatureAccess::RESOURCE_USERS), 'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_USERS)],
            'can' => ['invite' => Gate::allows('member.invite'), 'manage' => Gate::allows('member.manage')],
        ]);
    }

    public function store(Request $request, TeamMembers $team): RedirectResponse
    {
        Gate::authorize('member.invite');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(array_keys(PermissionCatalog::tenantRoles()))],
            'warehouses' => ['array'],
            'warehouses.*' => ['uuid'],
        ]);

        // Seul un administrateur peut nommer un autre administrateur.
        if ($data['role'] === PermissionCatalog::ADMIN_ROLE) {
            Gate::authorize('member.manage');
        }

        /** @var User $actor */
        $actor = $request->user();
        $team->invite($data['name'], $data['email'], $data['role'], array_values($data['warehouses'] ?? []), $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.invited', ['email' => $data['email']])]);

        return back();
    }

    public function update(Request $request, TenantMembership $membership, TeamMembers $team): RedirectResponse
    {
        Gate::authorize('member.manage');
        $this->ensureSameTenant($membership);
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(PermissionCatalog::tenantRoles()))],
            'warehouses' => ['array'],
            'warehouses.*' => ['uuid'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $team->update($membership, $data['role'], array_values($data['warehouses'] ?? []), $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.updated', ['name' => $membership->user->name])]);

        return back();
    }

    public function suspend(Request $request, TenantMembership $membership, TeamMembers $team): RedirectResponse
    {
        Gate::authorize('member.manage');
        $this->ensureSameTenant($membership);
        /** @var User $actor */
        $actor = $request->user();
        $team->suspend($membership, $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.suspended', ['name' => $membership->user->name])]);

        return back();
    }

    public function reactivate(Request $request, TenantMembership $membership, TeamMembers $team): RedirectResponse
    {
        Gate::authorize('member.manage');
        $this->ensureSameTenant($membership);
        /** @var User $actor */
        $actor = $request->user();
        $team->reactivate($membership, $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.reactivated', ['name' => $membership->user->name])]);

        return back();
    }

    public function resend(Request $request, TenantMembership $membership, TeamMembers $team): RedirectResponse
    {
        Gate::authorize('member.invite');
        $this->ensureSameTenant($membership);
        /** @var User $actor */
        $actor = $request->user();
        $team->resend($membership, $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.team.resent', ['email' => $membership->user->email])]);

        return back();
    }

    /**
     * Un membre d'une autre entreprise n'existe pas pour l'administrateur courant.
     */
    private function ensureSameTenant(TenantMembership $membership): void
    {
        abort_unless($membership->tenant_id === app(TenantContext::class)->tenantId(), 404);
    }
}
