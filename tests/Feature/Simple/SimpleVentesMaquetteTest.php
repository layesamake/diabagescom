<?php

namespace Tests\Feature\Simple;

use App\Domain\Sales\SalesDayLedger;
use App\Http\Middleware\ResolveTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 3 « Mes ventes » aligné sur la maquette 04a.
 *
 * Chaque ligne de vente expose maintenant les premiers articles (badge
 * initiale + quantité) et le mode principal utilisé (espèces, téléphone,
 * crédit). On s'aligne sur le pattern SimpleModeHomeTest : makeProduct +
 * sell() + act() pour valider la chaîne complète.
 */
class SimpleVentesMaquetteTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{tenant: mixed, user: mixed, warehouse: mixed, register: mixed}
     */
    private function vendeur(): array
    {
        $setup = $this->createTenantWithMember();

        return $this->member($setup, 'cashier');
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_sales_ledger_exposes_top_products_and_cash_method(): void
    {
        $v = $this->vendeur();
        // makeProduct fixe sale_price = cost * 2 ; cost=1000 → sale_price=2000
        $riz = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $riz->id, '10', 1000]]);
        $session = $this->openSession($v, 0);

        $this->sell($v, $session, [[$riz->id, '2']], [['cash', 4000]]);

        $this->enter($v);
        $ledger = app(SalesDayLedger::class);
        $detail = $ledger->detailForDay(now(), $v['user'], false);

        $this->assertCount(1, $detail['sales']);
        $sale = $detail['sales'][0];
        $this->assertSame('cash', $sale['method']);
        $this->assertSame(4000, $sale['total']);
        $this->assertSame(0, $sale['balance']);
        $this->assertCount(1, $sale['products']);
        $this->assertSame($riz->id, $sale['products'][0]['id']);
        $this->assertSame('2.000', $sale['products'][0]['quantity']);
        $this->assertSame('AR', $sale['products'][0]['initials']);
    }

    public function test_the_sales_ledger_uses_mobile_method_for_wave(): void
    {
        $v = $this->vendeur();
        $riz = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $riz->id, '10', 1000]]);
        $session = $this->openSession($v, 0);

        $this->sell($v, $session, [[$riz->id, '2']], [['wave', 4000]]);

        $this->enter($v);
        $ledger = app(SalesDayLedger::class);
        $detail = $ledger->detailForDay(now(), $v['user'], false);

        $this->assertSame('mobile', $detail['sales'][0]['method']);
    }

    public function test_the_sales_ledger_flags_credit_when_balance_remains(): void
    {
        $v = $this->vendeur();
        $riz = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $riz->id, '10', 1000]]);
        $session = $this->openSession($v, 0);
        $client = $this->makeCustomer($v, creditLimit: 10000, terms: 7);

        $this->sell($v, $session, [[$riz->id, '1']], [['cash', 500]], $client->id);

        $this->enter($v);
        $ledger = app(SalesDayLedger::class);
        $detail = $ledger->detailForDay(now(), $v['user'], false);

        $this->assertSame('credit', $detail['sales'][0]['method']);
        $this->assertSame(1500, $detail['sales'][0]['balance']);
    }

    public function test_the_sales_page_renders_with_three_method_cards(): void
    {
        $v = $this->vendeur();
        $riz = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $riz->id, '10', 1000]]);
        $session = $this->openSession($v, 0);

        $this->sell($v, $session, [[$riz->id, '2']], [['cash', 4000]]);

        $this->act($v)
            ->get('/simple/ventes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Ventes')
                ->has('by_method.cash')
                ->has('by_method.mobile')
                ->has('by_method.credit')
                ->has('sales', 1)
                ->where('sales.0.method', 'cash')
                ->where('sales.0.total', 4000)
                ->where('sales.0.products.0.initials', 'AR')
                ->where('sales.0.products.0.quantity', '2.000'));
    }
}
