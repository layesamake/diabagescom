<?php

namespace App\Domain\Identity;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Aligne idempotemment la base sur PermissionCatalog : permissions, rôles intégrés
 * et leurs permissions. Exécuté au déploiement (diaba:sync-permissions) et dans les tests.
 *
 * Une permission retirée du catalogue n'est pas supprimée si elle est encore
 * référencée par un rôle personnalisé : la contrainte de clé étrangère l'en empêche.
 */
final class SyncPermissions
{
    public function __invoke(): void
    {
        DB::transaction(function (): void {
            foreach (PermissionCatalog::all() as $code => $definition) {
                Permission::query()->updateOrCreate(['code' => $code], $definition);
            }

            $this->syncRoles(PermissionCatalog::SCOPE_TENANT, PermissionCatalog::tenantRoles());
            $this->syncRoles(PermissionCatalog::SCOPE_PLATFORM, PermissionCatalog::platformRoles());
        });
    }

    /**
     * @param  array<string, array{name: string, permissions: list<string>}>  $roles
     */
    private function syncRoles(string $scope, array $roles): void
    {
        foreach ($roles as $code => $definition) {
            $role = Role::query()->firstOrCreate(
                ['tenant_id' => null, 'scope' => $scope, 'code' => $code],
                ['name' => $definition['name'], 'system' => true],
            );
            $role->update(['name' => $definition['name'], 'system' => true]);
            $role->permissions()->sync($definition['permissions']);
        }
    }
}
