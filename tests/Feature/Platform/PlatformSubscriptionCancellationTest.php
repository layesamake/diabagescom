<?php

namespace Tests\Feature\Platform;

use App\Domain\Tenancy\TenantScope;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    public function test_owner_can_cancel_immediately_and_reactivate_with_a_new_cycle(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $admin = $this->superAdmin();
        $subscription = $this->subscription($setup['tenant']->id);

        $this->actingAs($admin)->post(route('platform.subscriptions.update', $subscription), [
            'action' => 'cancel_now',
            'reason' => 'Demande de résiliation immédiate',
        ])->assertRedirect();

        $this->assertSame(Subscription::CANCELLED, $subscription->fresh()?->status);
        $this->assertNull($subscription->fresh()?->cancel_at);

        $this->actingAs($admin)->post(route('platform.subscriptions.update', $subscription), [
            'action' => 'activate',
            'billing_cycle' => 'monthly',
            'reason' => 'Réactivation validée par le client',
        ])->assertRedirect();

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()?->status);
    }

    public function test_owner_can_schedule_and_cancel_a_resiliation_before_its_due_date(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $admin = $this->superAdmin();
        $subscription = $this->subscription($setup['tenant']->id);
        $subscription->forceFill([
            'status' => Subscription::ACTIVE,
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(29),
        ])->save();

        $this->actingAs($admin)->post(route('platform.subscriptions.update', $subscription), [
            'action' => 'cancel_at_period_end',
            'reason' => 'Le client termine sa période en cours',
        ])->assertRedirect();

        $this->assertNotNull($subscription->fresh()?->cancel_at);
        $this->assertSame(
            $subscription->fresh()?->current_period_end?->toIso8601String(),
            $subscription->fresh()?->cancel_at?->toIso8601String(),
        );

        $this->actingAs($admin)->post(route('platform.subscriptions.update', $subscription), [
            'action' => 'reactivate',
            'reason' => 'Le client conserve son abonnement',
        ])->assertRedirect();

        $this->assertNull($subscription->fresh()?->cancel_at);
        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()?->status);
    }

    public function test_scheduled_cancellation_becomes_cancelled_when_the_subscription_tick_runs(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $admin = $this->superAdmin();
        $subscription = $this->subscription($setup['tenant']->id);
        $subscription->forceFill([
            'status' => Subscription::ACTIVE,
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subMinute(),
            'cancel_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($admin)->get(route('platform.tenants.show', $setup['tenant']))->assertOk();
        $this->artisan('diaba:subscriptions:tick')->assertSuccessful();

        $this->assertSame(Subscription::CANCELLED, $subscription->fresh()?->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'subscription.cancelled',
            'actor_id' => null,
            'tenant_id' => $setup['tenant']->id,
        ]);
    }

    private function subscription(string $tenantId): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->sole();
    }
}
