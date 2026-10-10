<?php

namespace App\Domain\Identity;

use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Évalue une permission du catalogue pour un utilisateur.
 *
 * - Permission tenant : uniquement via l'appartenance active du contexte courant.
 *   Un super-administrateur n'a aucun droit tenant implicite.
 * - Permission plateforme : uniquement via le rôle plateforme de l'utilisateur.
 *
 * Les permissions d'un rôle sont mises en cache pour la durée de la requête.
 */
final class Authorizer
{
    /** @var array<string, list<string>> */
    private array $rolePermissions = [];

    public function __construct(private readonly TenantContext $context) {}

    public function allows(User $user, string $permission): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return match (PermissionCatalog::scopeOf($permission)) {
            PermissionCatalog::SCOPE_TENANT => $this->allowsInTenant($user, $permission),
            PermissionCatalog::SCOPE_PLATFORM => $this->allowsOnPlatform($user, $permission),
            default => false,
        };
    }

    /**
     * Permissions tenant accordées dans le contexte courant (pour l'affichage du menu).
     *
     * @return list<string>
     */
    public function grantedTenantPermissions(User $user): array
    {
        $membership = $this->currentMembership($user);

        if ($membership === null) {
            return [];
        }

        if ($membership->isAdmin()) {
            return PermissionCatalog::tenantPermissions();
        }

        return $this->permissionsOf($membership->role);
    }

    private function allowsInTenant(User $user, string $permission): bool
    {
        $membership = $this->currentMembership($user);

        if ($membership === null) {
            return false;
        }

        return $membership->isAdmin() || in_array($permission, $this->permissionsOf($membership->role), true);
    }

    private function allowsOnPlatform(User $user, string $permission): bool
    {
        $role = $user->platformRole;

        return $role !== null
            && $role->scope === PermissionCatalog::SCOPE_PLATFORM
            && in_array($permission, $this->permissionsOf($role), true);
    }

    private function currentMembership(User $user): ?TenantMembership
    {
        $membership = $this->context->membership();

        if ($membership === null || $membership->user_id !== $user->id || ! $membership->isActive()) {
            return null;
        }

        return $membership;
    }

    /**
     * @return list<string>
     */
    private function permissionsOf(Role $role): array
    {
        return $this->rolePermissions[$role->id] ??= array_values(DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all());
    }
}
