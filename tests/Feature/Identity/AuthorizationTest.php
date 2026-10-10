<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Authorizer;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function enter(array $setup): Authorizer
    {
        app(TenantContext::class)->set($setup['tenant'], $setup['membership']->load('role', 'tenant'), $setup['warehouse']);

        return app(Authorizer::class);
    }

    public function test_admin_has_every_tenant_permission_but_no_platform_permission(): void
    {
        $setup = $this->createTenantWithMember('admin');
        $authorizer = $this->enter($setup);

        foreach (PermissionCatalog::tenantPermissions() as $permission) {
            $this->assertTrue($authorizer->allows($setup['user'], $permission), $permission);
        }

        $this->assertFalse($authorizer->allows($setup['user'], 'platform.tenants.manage'));
    }

    public function test_cashier_permissions_follow_the_matrix(): void
    {
        $setup = $this->createTenantWithMember('cashier');
        $authorizer = $this->enter($setup);

        $this->assertTrue($authorizer->allows($setup['user'], 'sale.validate'));
        $this->assertTrue($authorizer->allows($setup['user'], 'cash.receive'));
        $this->assertFalse($authorizer->allows($setup['user'], 'sale.sell_negative_stock'));
        $this->assertFalse($authorizer->allows($setup['user'], 'catalog.price_override'));
        $this->assertFalse($authorizer->allows($setup['user'], 'member.manage'));
        $this->assertFalse($authorizer->allows($setup['user'], 'audit.view'));
    }

    public function test_permissions_do_not_leak_to_another_company(): void
    {
        $admin = $this->createTenantWithMember('admin');
        $other = $this->createTenantWithMember('admin');

        // Contexte de l'autre entreprise : l'admin de A n'y a aucun droit.
        app(TenantContext::class)->set($other['tenant'], $other['membership']->load('role', 'tenant'));

        $this->assertFalse(app(Authorizer::class)->allows($admin['user'], 'organization.manage'));
    }

    public function test_super_admin_has_no_implicit_tenant_permission(): void
    {
        $setup = $this->createTenantWithMember('admin');
        $superAdmin = User::factory()->withTwoFactor()->create();
        $superAdmin->platform_role_id = Role::system('platform', 'super_admin')->id;
        $superAdmin->save();

        $authorizer = $this->enter($setup);

        $this->assertTrue($authorizer->allows($superAdmin, 'platform.tenants.manage'));
        $this->assertFalse($authorizer->allows($superAdmin, 'sale.view'));
    }

    public function test_suspended_users_are_denied_everything(): void
    {
        $setup = $this->createTenantWithMember('admin');
        $setup['user']->forceFill(['status' => 'suspended'])->save();

        $this->assertFalse($this->enter($setup)->allows($setup['user'], 'sale.view'));
    }

    public function test_gate_is_wired_to_the_catalog(): void
    {
        $setup = $this->createTenantWithMember('cashier');

        $this->actingAs($setup['user'])->get(route('audit.index'))->assertForbidden();
    }

    public function test_database_refuses_a_platform_role_on_a_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $this->expectException(QueryException::class);

        $membership = new TenantMembership;
        $membership->tenant_id = $tenant->id;
        $membership->user_id = $user->id;
        $membership->role_id = Role::system('platform', 'super_admin')->id;
        $membership->save();
    }

    public function test_database_refuses_a_tenant_role_as_platform_role(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->platform_role_id = Role::system('tenant', 'admin')->id;
        $user->save();
    }

    public function test_permission_sync_is_idempotent(): void
    {
        $this->artisan('diaba:sync-permissions')->assertSuccessful();
        $this->artisan('diaba:sync-permissions')->assertSuccessful();

        $this->assertDatabaseCount('permissions', count(PermissionCatalog::all()));
        $this->assertDatabaseCount('roles', count(PermissionCatalog::tenantRoles()) + count(PermissionCatalog::platformRoles()));
    }
}
