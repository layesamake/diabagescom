<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 3 : Accueil et Mes ventes.
 *
 * L'accueil montre les chiffres du jour du vendeur (pas un rapport de
 * gestion) : chaque vendeur voit toujours ses propres ventes, l'admin voit
 * tout. Mes ventes reprend la même règle sur un jour choisi.
 */
class SimpleModeHomeTest extends TestCase
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

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    // ── Accueil ─────────────────────────────────────────────────────────

    public function test_the_home_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $this->act($v)->get('/simple')->assertNotFound();
    }

    public function test_the_home_page_shows_the_vendors_own_sales_of_the_day(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($v, 0);
        $this->sell($v, $session, [[$rice->id, '2']], [['cash', 2000]]);

        $other = $this->member($admin, 'cashier');
        $otherSession = $this->openSession($other, 0);
        $this->sell($other, $otherSession, [[$rice->id, '1']], [['cash', 1000]]);

        $this->act($v)
            ->get('/simple')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Accueil')
                ->where('figures.today.total', 2000)
                ->where('figures.today.count', 1)
                ->has('figures.yesterday')
                ->has('tiles')
                ->has('alerts'));
    }

    public function test_the_home_page_offers_sell_and_cash_tiles_to_a_cashier(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');

        $this->act($v)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('tiles', ['sell', 'cash']));
    }

    public function test_an_admin_sees_the_total_of_every_cashier_at_home(): void
    {
        $admin = $this->createTenantWithMember();
        $v1 = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1000);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1000]]);
        $session1 = $this->openSession($v1, 0);
        $this->sell($v1, $session1, [[$rice->id, '1']], [['cash', 1000]]);

        $v2 = $this->member($admin, 'cashier');
        $session2 = $this->openSession($v2, 0);
        $this->sell($v2, $session2, [[$rice->id, '1']], [['wave', 1000]]);

        $this->act($admin)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('figures.today.total', 2000)
                ->where('figures.today.count', 2));
    }

    // ── Mes ventes ──────────────────────────────────────────────────────

    public function test_the_sales_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $this->act($v)->get('/simple/ventes')->assertNotFound();
    }

    public function test_a_cashier_only_sees_their_own_sales(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1500);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1500]]);
        $session = $this->openSession($v, 0);
        $this->sell($v, $session, [[$rice->id, '2']], [['cash', 3000]]);

        $other = $this->member($admin, 'cashier');
        $otherSession = $this->openSession($other, 0);
        $this->sell($other, $otherSession, [[$rice->id, '1']], [['wave', 1500]]);

        $this->act($v)
            ->get('/simple/ventes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Ventes')
                ->where('total', 3000)
                ->has('sales', 1)
                ->where('by_method.cash', 3000)
                ->where('by_method.mobile', 0));
    }

    public function test_an_admin_sees_every_sale_of_the_day(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1500);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1500]]);
        $session = $this->openSession($v, 0);
        $this->sell($v, $session, [[$rice->id, '1']], [['cash', 1500]]);

        $other = $this->member($admin, 'cashier');
        $otherSession = $this->openSession($other, 0);
        $this->sell($other, $otherSession, [[$rice->id, '1']], [['wave', 1500]]);

        $this->act($admin)
            ->get('/simple/ventes')
            ->assertInertia(fn ($page) => $page
                ->where('total', 3000)
                ->has('sales', 2));
    }

    public function test_a_different_day_can_be_requested(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1000);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($v, 0);
        $this->sell($v, $session, [[$rice->id, '1']], [['cash', 1000]]);

        $this->act($v)
            ->get('/simple/ventes?day=2026-10-19')
            ->assertInertia(fn ($page) => $page
                ->where('day', '2026-10-19')
                ->where('total', 0)
                ->has('sales', 0));
    }

    public function test_a_cashier_cannot_cancel_a_sale(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1000);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($v, 0);
        $invoice = $this->sell($v, $session, [[$rice->id, '1']], [['cash', 1000]]);

        $this->act($v)
            ->post("/simple/ventes/{$invoice->id}/annuler", ['reason' => 'Erreur de saisie'])
            ->assertForbidden();
    }

    public function test_an_admin_can_cancel_a_sale_from_the_simple_sales_screen(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $rice = $this->makeProduct($admin, 'RIZ', 'U', 1000);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($v, 0);
        $invoice = $this->sell($v, $session, [[$rice->id, '1']], [['cash', 1000]]);

        $this->act($admin)
            ->post("/simple/ventes/{$invoice->id}/annuler", ['reason' => 'Erreur de saisie'])
            ->assertRedirect('/simple/ventes');

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }
}
