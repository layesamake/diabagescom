<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SimpleModeStockTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_simple_stock_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');

        $this->act($manager)->get('/simple/stock')->assertNotFound();
    }

    public function test_simple_stock_shows_balances_and_availability_counters(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');
        $rice = $this->makeProduct($manager, 'RIZ', 'U', 1500);

        $this->move($manager, 'initial', [[$manager['warehouse']->id, $rice->id, '12', 1500]]);

        $this->act($manager)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Stock')
                ->where('warehouse.id', $manager['warehouse']->id)
                ->where('counters.enough', 1)
                ->where('counters.low', 0)
                ->where('counters.empty', 0)
                ->where('products.0.id', $rice->id)
                ->where('products.0.quantity', '12.000')
                ->where('products.0.status', 'enough'));
    }
}
