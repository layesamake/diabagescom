<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', false);
    }

    public function test_admin_without_mfa_is_redirected_to_security_settings(): void
    {
        $setup = $this->createTenantWithMember('admin', User::factory()->create());

        $this->actingAs($setup['user'])
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_admin_without_mfa_gets_403_on_json_requests(): void
    {
        $setup = $this->createTenantWithMember('admin', User::factory()->create());

        $this->actingAs($setup['user'])
            ->getJson(route('audit.index'))
            ->assertForbidden();
    }

    public function test_admin_with_mfa_can_access(): void
    {
        $setup = $this->createTenantWithMember('admin');

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertOk();
    }

    public function test_non_admin_members_are_not_forced_to_use_mfa(): void
    {
        $setup = $this->createTenantWithMember('cashier', User::factory()->create());

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertOk();
    }

    public function test_security_settings_stay_reachable_without_a_company(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }
}
