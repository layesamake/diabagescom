<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — accueil : tuiles d'action selon les permissions du rôle.
 *
 * Le caissier (rôle le plus courant au quotidien) voit vendre, caisse et
 * clients (qui a une dette), mais pas le stock (géré par un autre rôle). Un
 * administrateur voit les quatre tuiles. Un gestionnaire de dépôt ne voit
 * que Stock : il n'a ni sale.validate ni customer.view.
 */
class SimpleAccueilTilesTest extends TestCase
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

    public function test_the_home_page_offers_sell_cash_and_customers_to_a_cashier(): void
    {
        $admin = $this->createTenantWithMember();
        $cashier = $this->member($admin, 'cashier');

        $this->act($cashier)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('tiles', ['sell', 'cash', 'customers']));
    }

    public function test_the_home_page_offers_all_four_tiles_to_an_admin(): void
    {
        $admin = $this->createTenantWithMember();

        $this->act($admin)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('tiles', ['sell', 'cash', 'stock', 'customers']));
    }

    public function test_the_home_page_offers_only_stock_to_a_warehouse_manager(): void
    {
        // Le gestionnaire de dépôt a inventory.view mais ni sale.validate
        // ni customer.view : il ne voit que la tuile Stock.
        $admin = $this->createTenantWithMember();
        $wm = $this->member($admin, 'warehouse_manager');

        $this->act($wm)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('tiles', ['stock']));
    }
}
