<?php

namespace Database\Factories;

use App\Domain\Identity\PermissionCatalog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'legal_name' => $name.' SARL',
            'display_name' => $name,
            'country_code' => 'SN',
            'timezone' => 'Africa/Dakar',
            'locale' => 'fr',
        ];
    }

    /**
     * Ajoute un membre actif avec le rôle intégré donné.
     */
    public static function addMember(Tenant $tenant, User $user, string $roleCode = PermissionCatalog::ADMIN_ROLE): TenantMembership
    {
        $membership = new TenantMembership;
        $membership->tenant_id = $tenant->id;
        $membership->user_id = $user->id;
        $membership->role_id = Role::system(PermissionCatalog::SCOPE_TENANT, $roleCode)->id;
        $membership->status = TenantMembership::STATUS_ACTIVE;
        $membership->save();

        return $membership;
    }
}
