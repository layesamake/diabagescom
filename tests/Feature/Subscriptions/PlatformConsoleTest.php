<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(bool $mfa = true): User
    {
        $user = $mfa ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    private function subscriptionOf(array $setup): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole();
    }

    public function test_super_admin_lists_companies_with_their_subscription(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($this->superAdmin())
            ->get(route('platform.tenants.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tenants.data.0.name', $setup['tenant']->display_name)
                ->where('tenants.data.0.subscription.status', 'trialing'));
    }

    public function test_companies_can_be_filtered_by_effective_status_and_plan(): void
    {
        $expired = $this->createTenantWithMember(plan: 'essential');
        $this->subscriptionOf($expired)->forceFill(['trial_ends_at' => now()->subDays(2)])->save();
        $trialing = $this->createTenantWithMember();
        $admin = $this->superAdmin();

        // Essai échu sans passage de la tâche planifiée : déjà en lecture seule.
        $this->actingAs($admin)->get(route('platform.tenants.index', ['status' => 'read_only']))
            ->assertInertia(fn ($page) => $page
                ->has('tenants.data', 1)
                ->where('tenants.data.0.id', $expired['tenant']->id)
                ->where('filters.status', 'read_only'));

        $this->actingAs($admin)->get(route('platform.tenants.index', ['status' => 'trialing']))
            ->assertInertia(fn ($page) => $page->has('tenants.data', 1)->where('tenants.data.0.id', $trialing['tenant']->id));

        $this->actingAs($admin)->get(route('platform.tenants.index', ['plan' => 'essential']))
            ->assertInertia(fn ($page) => $page->has('tenants.data', 1)->where('tenants.data.0.id', $expired['tenant']->id));

        // Un statut inconnu est ignoré.
        $this->actingAs($admin)->get(route('platform.tenants.index', ['status' => 'nope']))
            ->assertInertia(fn ($page) => $page->has('tenants.data', 2)->where('filters.status', ''));
    }

    public function test_company_record_shows_usage_contacts_and_subscription_history(): void
    {
        $setup = $this->createTenantWithMember();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('platform.subscriptions.update', $this->subscriptionOf($setup)->id), [
            'action' => 'extend_trial', 'days' => 5, 'reason' => 'Démonstration prolongée',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('platform.tenants.show', $setup['tenant']->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('platform/TenantShow')
                ->where('tenant.name', $setup['tenant']->display_name)
                ->where('subscription.status', 'trialing')
                ->where('usage.users', 1)
                ->where('usage.warehouses', 1)
                ->where('contacts.0.email', $setup['user']->email)
                ->where('history.0.action', 'subscription.trial_extended')
                ->where('history.0.reason', 'Démonstration prolongée')
                ->where('canManageBilling', true));

        $this->actingAs($setup['user'])->get(route('platform.tenants.show', $setup['tenant']->id))->assertForbidden();
    }

    public function test_super_admin_must_use_mfa(): void
    {
        $this->actingAs($this->superAdmin(mfa: false))
            ->get(route('platform.tenants.index'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_company_admins_cannot_open_the_console(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('platform.tenants.index'))->assertForbidden();
        $this->actingAs($setup['user'])->get(route('platform.plans.index'))->assertForbidden();
    }

    public function test_super_admin_has_no_access_to_company_space(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('dashboard'))
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_manual_actions_require_a_reason_and_are_audited_in_the_company_log(): void
    {
        $setup = $this->createTenantWithMember();
        $admin = $this->superAdmin();
        $subscription = $this->subscriptionOf($setup);

        $this->actingAs($admin)
            ->post(route('platform.subscriptions.update', $subscription->id), ['action' => 'activate', 'billing_cycle' => 'yearly', 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post(route('platform.subscriptions.update', $subscription->id), [
                'action' => 'activate', 'billing_cycle' => 'yearly', 'reason' => 'Virement reçu, réf. VIR-2026-001',
            ])->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('yearly', $subscription->billing_cycle);

        $event = AuditEvent::query()->where('action', 'subscription.activated')->sole();
        $this->assertSame($setup['tenant']->id, $event->tenant_id);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('Virement reçu, réf. VIR-2026-001', $event->metadata['reason']);
    }

    public function test_extending_a_trial_restores_write_access(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(12)->days();
        $this->artisan('diaba:subscriptions:tick');

        $this->actingAs($this->superAdmin())->post(route('platform.subscriptions.update', $this->subscriptionOf($setup)->id), [
            'action' => 'extend_trial', 'days' => 5, 'reason' => 'Démonstration commerciale prolongée',
        ])->assertSessionHasNoErrors();

        $this->assertSame('trialing', $this->subscriptionOf($setup)->status);
        $this->actingAs($setup['user'])->post(route('warehouses.store'), ['name' => 'Second', 'code' => 'W2'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('warehouses', ['code' => 'W2']);
    }

    public function test_plans_can_be_updated_with_an_audit_trail(): void
    {
        $plan = Plan::query()->where('code', 'essential')->sole();

        $this->actingAs($this->superAdmin())->put(route('platform.plans.update', $plan), [
            'name' => 'Essentiel', 'warehouse_limit' => 1, 'included_users' => 3, 'accounting_enabled' => false,
            'price_monthly' => 15000, 'price_yearly' => 150000, 'active' => true, 'reason' => 'Grille tarifaire validée',
        ])->assertSessionHasNoErrors();

        $this->assertSame(15000, $plan->fresh()?->price_monthly);
        $this->assertDatabaseHas('audit_events', ['action' => 'platform.plan_updated', 'entity_id' => $plan->id]);
    }

    public function test_plan_sync_never_overwrites_existing_plans(): void
    {
        Plan::query()->where('code', 'essential')->update(['included_users' => 9]);

        $this->artisan('diaba:sync-plans')->assertSuccessful();

        $this->assertSame(9, Plan::query()->where('code', 'essential')->value('included_users'));
    }
}
