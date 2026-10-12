<?php

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformTenantAccountTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    public function test_owner_can_resend_a_client_admin_invitation(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();

        $this->actingAs($this->superAdmin())
            ->post(route('platform.members.resend', [$setup['tenant']->id, $setup['membership']->id]))
            ->assertRedirect();

        Notification::assertSentTo($setup['user'], TeamInvitationNotification::class);
    }

    public function test_owner_can_suspend_and_reactivate_a_client_admin_without_removing_the_last_admin(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $secondAdmin = User::factory()->create();
        $membership = new TenantMembership;
        $membership->forceFill([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $secondAdmin->id,
            'role_id' => Role::system('tenant', 'admin')->id,
            'status' => TenantMembership::STATUS_ACTIVE,
        ])->save();

        $owner = $this->superAdmin();
        $this->actingAs($owner)
            ->post(route('platform.members.suspend', [$setup['tenant']->id, $membership->id]))
            ->assertRedirect();
        $this->assertSame(TenantMembership::STATUS_SUSPENDED, $membership->fresh()?->status);

        $this->actingAs($owner)
            ->post(route('platform.members.reactivate', [$setup['tenant']->id, $membership->id]))
            ->assertRedirect();
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->fresh()?->status);

        $this->actingAs($owner)
            ->post(route('platform.members.suspend', [$setup['tenant']->id, $membership->id]))
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('platform.members.suspend', [$setup['tenant']->id, $setup['membership']->id]))
            ->assertSessionHasErrors('member');
    }
}
