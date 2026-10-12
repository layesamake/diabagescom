<?php

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformTenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'owner_name' => 'Awa Diallo',
            'owner_email' => 'awa@example.test',
            'legal_name' => 'Boutique Diallo SARL',
            'display_name' => 'Boutique Diallo',
            'country_code' => 'SN',
            'timezone' => 'Africa/Dakar',
            'warehouse_name' => 'Dakar Centre',
            'warehouse_code' => 'dkr-1',
            'plan_code' => 'essential',
            ...$overrides,
        ];
    }

    public function test_platform_admin_can_provision_a_client_and_send_an_invitation(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('platform.tenants.store'), $this->payload())
            ->assertRedirect();

        $client = User::query()->where('email', 'awa@example.test')->sole();
        $tenant = Tenant::query()->sole();

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $client->id,
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => Subscription::TRIALING,
        ]);
        $this->assertSame($client->id, $tenant->created_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.created', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'subscription.trial_started', 'actor_id' => $admin->id]);

        Notification::assertSentTo($client, TeamInvitationNotification::class, fn (TeamInvitationNotification $notification): bool => $notification->newAccount);
    }

    public function test_platform_console_cannot_be_used_to_create_a_tenant_for_a_platform_account(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->from(route('platform.tenants.create'))
            ->post(route('platform.tenants.store'), $this->payload([
                'owner_name' => $admin->name,
                'owner_email' => $admin->email,
            ]))
            ->assertSessionHasErrors('owner_email');

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_company_admin_cannot_use_the_platform_provisioning_form(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])
            ->get(route('platform.tenants.create'))
            ->assertForbidden();
    }
}
