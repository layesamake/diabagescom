<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialExtensionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that extending trial succeeds without throwing 500 error
     */
    public function test_extend_trial_succeeds_with_valid_input(): void
    {
        // Setup
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->where('code', 'business')->first();
        $plan ??= Plan::query()->firstOrFail();
        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->forPlan($plan)
            ->trialing()
            ->create();

        $initialTrialEndsAt = $subscription->trial_ends_at;

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Customer request - extending trial for testing',
                'days' => 14,
            ]);

        // Assert
        $response->assertRedirect();
        $subscription->refresh();
        
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertTrue(
            $subscription->trial_ends_at->isAfter($initialTrialEndsAt),
            'Trial should be extended'
        );
        $this->assertEquals(
            14,
            $subscription->trial_ends_at->diffInDays($initialTrialEndsAt),
            'Trial should be extended by exactly 14 days'
        );
    }

    /**
     * Test that extend_trial respects min/max days validation
     */
    public function test_extend_trial_validates_day_range(): void
    {
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->firstOrFail();
        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->forPlan($plan)
            ->trialing()
            ->create();

        // Test with 0 days (invalid)
        $response = $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Test reason for validation',
                'days' => 0,
            ]);

        $response->assertStatus(422);

        // Test with 61 days (too many)
        $response = $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Test reason for validation',
                'days' => 61,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test that extend_trial requires minimum reason length
     */
    public function test_extend_trial_validates_reason_length(): void
    {
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->firstOrFail();
        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->forPlan($plan)
            ->trialing()
            ->create();

        // Test with reason too short
        $response = $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Too short',  // Less than 5 chars requirement
                'days' => 14,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test that audit log is created for trial extension
     */
    public function test_extend_trial_creates_audit_log(): void
    {
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->firstOrFail();
        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->forPlan($plan)
            ->trialing()
            ->create();

        // Act
        $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Testing audit log creation',
                'days' => 7,
            ]);

        // Assert - Check that audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
            'action' => 'subscription.trial_extended',
        ]);
    }

    /**
     * Test that subscription state is consistent after extension
     */
    public function test_extend_trial_maintains_subscription_state(): void
    {
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->firstOrFail();
        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->forPlan($plan)
            ->trialing()
            ->create();

        // Store original values
        $originalStatus = $subscription->status;
        $originalPlanId = $subscription->plan_id;

        // Act
        $this->actingAs($admin)
            ->postJson(route('platform.tenants.subscriptions.update', [
                'subscription' => $subscription,
            ]), [
                'action' => 'extend_trial',
                'reason' => 'Consistency test for trial extension',
                'days' => 10,
            ]);

        // Assert - Verify other fields are unchanged
        $subscription->refresh();
        $this->assertEquals($originalStatus, $subscription->status, 'Status should remain TRIALING');
        $this->assertEquals($originalPlanId, $subscription->plan_id, 'Plan ID should not change');
    }
}
