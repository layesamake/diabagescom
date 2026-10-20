<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Accueil : les actions rapides suivent le métier de chaque rôle.
 */
class DashboardActionsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * @param  list<string>  $expected
     */
    private function assertActions(array $setup, array $expected): void
    {
        $this->actingAs($setup['user'])->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('actions', $expected));
    }

    public function test_the_admin_gets_the_core_actions_capped_at_six(): void
    {
        $this->assertActions($this->createTenantWithMember(), ['sell', 'quote', 'cash', 'purchase', 'product', 'stock']);
    }

    public function test_each_role_gets_the_actions_of_its_job(): void
    {
        $setup = $this->createTenantWithMember();

        $this->assertActions($this->member($setup, 'cashier'), ['sell', 'quote', 'cash', 'customer']);
        $this->assertActions($this->member($setup, 'warehouse_manager'), ['stock', 'receive', 'transfer', 'count', 'product']);
        $this->assertActions($this->member($setup, 'purchaser'), ['purchase', 'request', 'supplier', 'stock', 'reports']);
        $this->assertActions($this->member($setup, 'accountant'), ['accounting', 'reports', 'cash', 'purchase']);
    }

    public function test_accounting_is_only_proposed_with_the_accounting_plan(): void
    {
        $this->assertActions($this->createTenantWithMember('accountant', null, 'business', false), ['reports', 'cash', 'purchase']);
    }
}
