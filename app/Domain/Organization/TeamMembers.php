<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Collaborateurs de l'entreprise : ajout (avec invitation par e-mail), rôle,
 * dépôts accessibles, suspension et réactivation. Chaque changement est
 * audité ; on ne peut ni se modifier soi-même, ni retirer le dernier
 * administrateur, ni dépasser le nombre d'utilisateurs de la formule.
 */
final class TeamMembers
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AddTenantMember $addMember,
        private readonly FeatureAccess $access,
        private readonly SubscriptionLifecycle $subscriptions,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $warehouseIds
     */
    public function invite(string $name, string $email, string $roleCode, array $warehouseIds, User $actor): TenantMembership
    {
        $tenant = $this->context->tenant();
        $email = mb_strtolower(trim($email));

        [$membership, $user, $newAccount] = DB::transaction(function () use ($name, $email, $roleCode, $warehouseIds): array {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            $newAccount = $user === null;

            if ($user === null) {
                // Compte créé sans mot de passe utilisable : la personne choisit le sien via l'invitation.
                $user = User::query()->forceCreate(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64))]);
            }

            if (TenantMembership::query()->where('tenant_id', $this->context->tenantId())->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('organization.team.errors.already_member')]);
            }

            $membership = ($this->addMember)($user, $roleCode);
            $this->syncWarehouses($membership, $roleCode, $warehouseIds);

            return [$membership, $user, $newAccount];
        });

        $link = $newAccount
            ? route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email])
            : route('login');
        $user->notify(new TeamInvitationNotification($tenant->display_name, $actor->name, $this->roleName($roleCode), $link, $newAccount));

        return $membership;
    }

    /**
     * @param  list<string>  $warehouseIds
     */
    public function update(TenantMembership $membership, string $roleCode, array $warehouseIds, User $actor): void
    {
        $this->guardOther($membership, $actor);
        $this->assertRole($roleCode);

        DB::transaction(function () use ($membership, $roleCode, $warehouseIds): void {
            $before = ['role' => $membership->role->code, 'warehouses' => $this->warehouseIds($membership)];

            if ($membership->isAdmin() && $roleCode !== PermissionCatalog::ADMIN_ROLE) {
                $this->guardLastAdmin($membership);
            }

            $membership->role_id = Role::system(PermissionCatalog::SCOPE_TENANT, $roleCode)->id;
            $membership->save();
            $membership->load('role');
            $this->syncWarehouses($membership, $roleCode, $warehouseIds);

            $this->audit->record('membership.updated', $membership, before: $before, after: ['role' => $roleCode, 'warehouses' => $this->warehouseIds($membership)]);
        });
    }

    public function suspend(TenantMembership $membership, User $actor): void
    {
        $this->guardOther($membership, $actor);

        DB::transaction(function () use ($membership): void {
            if ($membership->isAdmin()) {
                $this->guardLastAdmin($membership);
            }

            $membership->status = TenantMembership::STATUS_SUSPENDED;
            $membership->save();
            $this->audit->record('membership.suspended', $membership, before: ['status' => TenantMembership::STATUS_ACTIVE], after: ['status' => TenantMembership::STATUS_SUSPENDED]);
        });
    }

    public function reactivate(TenantMembership $membership, User $actor): void
    {
        $this->guardOther($membership, $actor);
        $tenant = $this->context->tenant();

        DB::transaction(function () use ($membership, $tenant): void {
            // La réactivation occupe de nouveau une place de la formule.
            $this->subscriptions->lockForTenant($tenant);
            $this->access->forget($tenant);
            $this->access->assertCanAdd($tenant, FeatureAccess::RESOURCE_USERS);

            $membership->status = TenantMembership::STATUS_ACTIVE;
            $membership->save();
            $this->audit->record('membership.reactivated', $membership, before: ['status' => TenantMembership::STATUS_SUSPENDED], after: ['status' => TenantMembership::STATUS_ACTIVE]);
        });
    }

    /**
     * Renvoie l'invitation (nouveau lien pour choisir son mot de passe).
     */
    public function resend(TenantMembership $membership, User $actor): void
    {
        $this->guardOther($membership, $actor);
        $user = $membership->user;
        $pending = $user->email_verified_at === null;
        $link = $pending
            ? route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email])
            : route('login');
        $user->notify(new TeamInvitationNotification($this->context->tenant()->display_name, $actor->name, $this->roleName($membership->role->code), $link, $pending));
        $this->audit->record('membership.invitation_sent', $membership);
    }

    public function roleName(string $roleCode): string
    {
        return PermissionCatalog::tenantRoles()[$roleCode]['name'] ?? $roleCode;
    }

    /**
     * @param  list<string>  $warehouseIds
     */
    private function syncWarehouses(TenantMembership $membership, string $roleCode, array $warehouseIds): void
    {
        DB::table('warehouse_memberships')->where('tenant_id', $membership->tenant_id)->where('membership_id', $membership->id)->delete();

        // L'administrateur voit tous les dépôts : aucune affectation à enregistrer.
        if ($roleCode === PermissionCatalog::ADMIN_ROLE) {
            return;
        }

        $valid = Warehouse::query()->whereIn('id', $warehouseIds)->where('status', Warehouse::STATUS_ACTIVE)->pluck('id');

        if ($valid->isEmpty()) {
            throw ValidationException::withMessages(['warehouses' => __('organization.team.errors.warehouse_required')]);
        }

        DB::table('warehouse_memberships')->insert($valid->map(fn (string $id) => [
            'tenant_id' => $membership->tenant_id, 'warehouse_id' => $id, 'membership_id' => $membership->id,
        ])->all());
    }

    /**
     * @return list<string>
     */
    private function warehouseIds(TenantMembership $membership): array
    {
        return array_values(array_map('strval', DB::table('warehouse_memberships')->where('membership_id', $membership->id)->orderBy('warehouse_id')->pluck('warehouse_id')->all()));
    }

    private function assertRole(string $roleCode): void
    {
        if (! array_key_exists($roleCode, PermissionCatalog::tenantRoles())) {
            throw ValidationException::withMessages(['role' => __('organization.team.errors.role')]);
        }
    }

    private function guardOther(TenantMembership $membership, User $actor): void
    {
        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages(['member' => __('organization.team.errors.self')]);
        }
    }

    private function guardLastAdmin(TenantMembership $membership): void
    {
        $admins = TenantMembership::query()->where('tenant_id', $membership->tenant_id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role_id', Role::system(PermissionCatalog::SCOPE_TENANT, PermissionCatalog::ADMIN_ROLE)->id)
            ->lockForUpdate()->count();

        if ($admins <= 1) {
            throw ValidationException::withMessages(['member' => __('organization.team.errors.last_admin')]);
        }
    }
}
