<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Organization\AddTenantMember;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\FeatureNotIncluded;
use App\Domain\Subscriptions\PlanLimitReached;
use App\Domain\Subscriptions\SubscriptionReadOnly;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les limites et l'état de l'abonnement sont appliqués côté serveur (critère EPIC-02).
 */
class FeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private function enter(array $setup): void
    {
        app(TenantContext::class)->set($setup['tenant'], $setup['membership']->load('role', 'tenant'), $setup['warehouse']);
    }

    private function warehousePayload(string $code = 'THS-1'): array
    {
        return ['name' => 'Thiès Marché', 'code' => $code];
    }

    public function test_essential_plan_allows_a_single_warehouse(): void
    {
        $setup = $this->createTenantWithMember(plan: 'essential');

        $this->actingAs($setup['user'])
            ->post(route('warehouses.store'), $this->warehousePayload())
            ->assertRedirect();

        $this->assertDatabaseMissing('warehouses', ['code' => 'THS-1']);
        $this->assertDatabaseCount('warehouses', 1);
    }

    public function test_business_plan_allows_several_warehouses_up_to_its_limit(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');

        foreach (['W2', 'W3', 'W4', 'W5'] as $code) {
            $this->actingAs($setup['user'])->post(route('warehouses.store'), $this->warehousePayload($code))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('warehouses', 5);

        $this->actingAs($setup['user'])->postJson(route('warehouses.store'), $this->warehousePayload('W6'))
            ->assertForbidden()
            ->assertJsonPath('message', __('subscription.errors.limit_warehouses', ['limit' => 5]));
    }

    public function test_an_extra_warehouse_addon_raises_the_limit(): void
    {
        $setup = $this->createTenantWithMember(plan: 'essential');
        $subscription = Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole();

        app(TenantContext::class)->runAs($setup['tenant'], fn () => SubscriptionAddon::query()->create([
            'subscription_id' => $subscription->id,
            'addon_code' => SubscriptionAddon::EXTRA_WAREHOUSE,
            'quantity' => 1,
            'starts_at' => now()->subMinute(),
        ]));

        $this->actingAs($setup['user'])->post(route('warehouses.store'), $this->warehousePayload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('warehouses', ['code' => 'THS-1']);
    }

    public function test_user_limit_is_enforced_when_adding_members(): void
    {
        $setup = $this->createTenantWithMember(plan: 'essential'); // 2 utilisateurs inclus
        $this->enter($setup);
        $add = app(AddTenantMember::class);

        $add(User::factory()->create(), 'cashier');

        $this->expectException(PlanLimitReached::class);
        $add(User::factory()->create(), 'cashier');
    }

    public function test_accounting_is_only_available_on_accounting_plans(): void
    {
        $without = $this->createTenantWithMember(plan: 'business');
        $with = $this->createTenantWithMember(plan: 'business_accounting');
        $access = app(FeatureAccess::class);

        $this->assertFalse($access->hasFeature($without['tenant'], 'accounting'));
        $this->assertTrue($access->hasFeature($with['tenant'], 'accounting'));

        $this->expectException(FeatureNotIncluded::class);
        $access->assertFeature($without['tenant'], 'accounting');
    }

    public function test_read_only_blocks_mutations_but_allows_reading(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(11)->days();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertOk();
        $this->actingAs($setup['user'])->get(route('warehouses.index'))->assertOk();

        $this->actingAs($setup['user'])->postJson(route('warehouses.store'), $this->warehousePayload())
            ->assertForbidden()
            ->assertJsonPath('message', __('subscription.errors.read_only'));
        $this->assertDatabaseMissing('warehouses', ['code' => 'THS-1']);
    }

    public function test_services_refuse_mutations_in_read_only_even_without_the_middleware(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(11)->days();
        $this->enter($setup);

        $this->expectException(SubscriptionReadOnly::class);
        app(AddTenantMember::class)(User::factory()->create(), 'cashier');
    }

    public function test_context_switch_stays_possible_in_read_only(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(11)->days();

        $this->actingAs($setup['user'])
            ->post(route('context.warehouse'), ['warehouse_id' => $setup['warehouse']->id])
            ->assertRedirect();
    }

    public function test_suspended_companies_only_reach_the_subscription_page(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(18)->days();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertRedirect(route('subscription.show'));
        $this->actingAs($setup['user'])->get(route('audit.index'))->assertRedirect(route('subscription.show'));
        $this->actingAs($setup['user'])->get(route('subscription.show'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscription.status', 'suspended'));
    }

    public function test_a_company_without_subscription_is_treated_as_suspended(): void
    {
        $setup = $this->createTenantWithMember(plan: null);

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertRedirect(route('subscription.show'));
    }

    public function test_shared_props_expose_the_trial_state(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(7)->days();

        $this->actingAs($setup['user'])->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('subscription.status', 'trialing')
                ->where('subscription.trial_days_left', 3)
                ->where('subscription.can_write', true)
                ->where('subscription.features.accounting', true));
    }

    public function test_downgrading_keeps_existing_warehouses(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($setup['user'])->post(route('warehouses.store'), $this->warehousePayload());
        $this->assertDatabaseCount('warehouses', 2);

        $subscription = Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole();
        $subscription->plan_id = Plan::query()->where('code', 'essential')->value('id');
        $subscription->save();
        app(FeatureAccess::class)->forget($setup['tenant']);

        // Rien n'est supprimé ; seule la création au-delà de la limite est refusée.
        $this->assertDatabaseCount('warehouses', 2);
        $this->actingAs($setup['user'])->postJson(route('warehouses.store'), $this->warehousePayload('W9'))->assertForbidden();
    }
}
