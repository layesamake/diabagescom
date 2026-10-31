<?php

namespace Tests\Feature\Platform;

use App\Domain\Tenancy\TenantScope;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialExtensionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Super-administrateur plateforme avec MFA, seule identité autorisée à
     * administrer les abonnements de toutes les entreprises.
     */
    private function platformAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    /**
     * @param  array{tenant: \App\Models\Tenant}  $setup
     */
    private function subscriptionOf(array $setup): Subscription
    {
        return Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $setup['tenant']->id)
            ->sole();
    }

    public function test_a_platform_admin_can_extend_a_trial_and_preserve_its_plan_and_status(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $subscription = $this->subscriptionOf($setup);
        $initialEndsAt = $subscription->trial_ends_at;

        $this->assertNotNull($initialEndsAt);

        $this->actingAs($this->platformAdmin())
            ->post(route('platform.subscriptions.update', $subscription), [
                'action' => 'extend_trial',
                'reason' => 'Démonstration commerciale prolongée',
                'days' => 14,
            ])
            ->assertRedirect();

        $subscription->refresh();

        $this->assertSame(Subscription::TRIALING, $subscription->status);
        $this->assertSame($setup['tenant']->id, $subscription->tenant_id);
        $this->assertSame('business', $subscription->plan->code);
        $this->assertTrue($subscription->trial_ends_at?->equalTo($initialEndsAt->addDays(14)) ?? false);
    }

    public function test_trial_extension_validates_the_day_range(): void
    {
        $subscription = $this->subscriptionOf($this->createTenantWithMember(plan: 'business'));
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.subscriptions.update', $subscription), [
                'action' => 'extend_trial',
                'reason' => 'Validation de la durée de prolongation',
                'days' => 0,
            ])
            ->assertSessionHasErrors('days');

        $this->actingAs($admin)
            ->post(route('platform.subscriptions.update', $subscription), [
                'action' => 'extend_trial',
                'reason' => 'Validation de la durée de prolongation',
                'days' => 61,
            ])
            ->assertSessionHasErrors('days');
    }

    public function test_trial_extension_requires_an_auditable_reason(): void
    {
        $subscription = $this->subscriptionOf($this->createTenantWithMember(plan: 'business'));

        $this->actingAs($this->platformAdmin())
            ->post(route('platform.subscriptions.update', $subscription), [
                'action' => 'extend_trial',
                'reason' => 'Non',
                'days' => 7,
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_trial_extension_is_audited_against_the_subscription_and_actor(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $subscription = $this->subscriptionOf($setup);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.subscriptions.update', $subscription), [
                'action' => 'extend_trial',
                'reason' => 'Client en attente de son financement',
                'days' => 7,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $setup['tenant']->id,
            'actor_id' => $admin->id,
            'action' => 'subscription.trial_extended',
            'entity_type' => 'Subscription',
            'entity_id' => $subscription->id,
        ]);
    }
}
