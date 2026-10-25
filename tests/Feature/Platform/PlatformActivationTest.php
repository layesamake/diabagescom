<?php

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformActivationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    public function test_dashboard_exposes_the_activation_funnel_and_stalled_tenants(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        Tenant::query()->whereKey($setup['tenant']->id)->update(['created_at' => now()->subDays(5)]);

        $this->actingAs($this->superAdmin())->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('platform/Dashboard')
                ->where('activation.total', 1)
                ->where('activation.steps.sale', 0)
                ->has('activation.steps.company')
                ->where('stalled.0.id', $setup['tenant']->id)
                ->has('stalled.0.next'));
    }

    public function test_recent_tenants_are_not_flagged_as_stalled(): void
    {
        $this->createTenantWithMember(plan: 'business');

        $this->actingAs($this->superAdmin())->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('stalled', 0));
    }

    public function test_tenant_page_shows_boolean_milestones_only(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');

        $this->actingAs($this->superAdmin())->get(route('platform.tenants.show', $setup['tenant']->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('milestones.sale', false)
                ->where('milestones.team', false)
                ->has('milestones', 8));
    }

    public function test_activation_is_reserved_to_the_platform(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('platform.dashboard'))->assertForbidden();
    }
}
