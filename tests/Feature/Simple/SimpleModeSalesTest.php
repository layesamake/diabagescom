<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\SalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 2 : vente comptoir et caisse simplifiées.
 *
 * Les écrans /simple/vendre et /simple/caisse sont de fines façades autour
 * des mêmes services métier que le mode expert. Une vente faite en mode simple
 * crée exactement la même facture, les mêmes paiements, mouvements de stock
 * et entrées d'audit qu'une vente experte.
 */
class SimpleModeSalesTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    /**
     * Crée un vendeur affecté au dépôt, avec le bon contexte pour les
     * requêtes HTTP et les helpers serveur.
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

    // ── Écran Vendre ────────────────────────────────────────────────────

    public function test_the_sell_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $v = $this->vendeur();
        $this->act($v)->get('/simple/vendre')->assertNotFound();
    }

    public function test_the_sell_page_loads_with_session_and_frequent_products(): void
    {
        $v = $this->vendeur();
        $this->openSession($v, 0);

        $this->act($v)
            ->get('/simple/vendre')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Vendre')
                ->has('sessions')
                ->has('registers')
                ->has('frequent')
                ->has('methods')
                ->has('can'));
    }

    public function test_a_simple_cash_sale_creates_the_same_invoice_as_the_expert_pos(): void
    {
        $v = $this->vendeur();
        $rice = $this->makeProduct($v, 'RIZ', 'U', 1500);
        $this->move($v, 'initial', [[$v['warehouse']->id, $rice->id, '10', 1500]]);
        $session = $this->openSession($v, 0);

        $key = (string) Str::uuid7();
        $this->act($v)
            ->post('/simple/vendre', [
                'idempotency_key' => $key,
                'warehouse_id' => $v['warehouse']->id,
                'cash_session_id' => $session->id,
                'customer_id' => null,
                'lines' => [['product_id' => $rice->id, 'quantity' => '2']],
                'tenders' => [['method' => 'cash', 'amount' => 6000, 'reference' => null]],
            ])
            ->assertRedirect('/simple/vendre');

        $invoice = SalesDocument::query()->where('idempotency_key', $key)->firstOrFail();
        $this->assertSame('invoice', $invoice->type);
        $this->assertSame('validated', $invoice->status);
        $this->assertSame(6000, $invoice->total);
        $this->assertSame(6000, $invoice->amount_paid);
    }

    public function test_a_simple_sale_replays_idempotently(): void
    {
        $v = $this->vendeur();
        $rice = $this->makeProduct($v, 'RIZ', 'U', 1500);
        $this->move($v, 'initial', [[$v['warehouse']->id, $rice->id, '10', 1500]]);
        $session = $this->openSession($v, 0);

        $key = (string) Str::uuid7();
        $payload = [
            'idempotency_key' => $key,
            'warehouse_id' => $v['warehouse']->id,
            'cash_session_id' => $session->id,
            'customer_id' => null,
            'lines' => [['product_id' => $rice->id, 'quantity' => '1']],
            'tenders' => [['method' => 'cash', 'amount' => 3000, 'reference' => null]],
        ];

        $this->act($v);
        $this->post('/simple/vendre', $payload)->assertRedirect();
        $this->post('/simple/vendre', $payload)->assertRedirect();

        $this->assertSame(1, SalesDocument::query()->where('idempotency_key', $key)->count());
    }

    public function test_a_simple_wave_sale_does_not_create_a_cash_movement(): void
    {
        $v = $this->vendeur();
        $rice = $this->makeProduct($v, 'RIZ', 'U', 1000);
        $this->move($v, 'initial', [[$v['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($v, 0);

        $this->act($v)
            ->post('/simple/vendre', [
                'idempotency_key' => (string) Str::uuid7(),
                'warehouse_id' => $v['warehouse']->id,
                'cash_session_id' => $session->id,
                'customer_id' => null,
                'lines' => [['product_id' => $rice->id, 'quantity' => '1']],
                'tenders' => [['method' => 'wave', 'amount' => 2000, 'reference' => 'W123']],
            ])
            ->assertRedirect();

        $this->assertSame(0, CashMovement::query()
            ->where('session_id', $session->id)
            ->where('type', '<>', 'opening')
            ->count());
    }

    // ── Écran Caisse ────────────────────────────────────────────────────

    public function test_the_cash_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $v = $this->vendeur();
        $this->act($v)->get('/simple/caisse')->assertNotFound();
    }

    public function test_the_cash_page_loads_with_register_and_session(): void
    {
        $v = $this->vendeur();
        $this->openSession($v, 0);

        $this->act($v)
            ->get('/simple/caisse')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Caisse')
                ->has('register')
                ->has('session')
                ->has('can'));
    }

    public function test_opening_a_register_from_simple_mode_redirects_to_the_simple_cash(): void
    {
        $v = $this->vendeur();
        $this->enter($v);
        $register = CashRegister::query()->where('warehouse_id', $v['warehouse']->id)->where('active', true)->firstOrFail();

        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", [
                'opening_amount' => 25000,
            ])
            ->assertRedirect('/simple/caisse');

        $this->assertTrue(CashSession::query()->where('register_id', $register->id)->where('status', 'open')->exists());
    }

    public function test_closing_a_session_from_simple_mode_redirects_to_the_simple_cash(): void
    {
        $v = $this->vendeur();
        $session = $this->openSession($v, 0);

        $this->act($v)
            ->post("/simple/caisse/{$session->id}/fermer", [
                'counted_amount' => $session->opening_amount,
                'note' => null,
            ])
            ->assertRedirect('/simple/caisse');

        $this->assertSame('closed', $session->fresh()->status);
    }

    public function test_a_cashier_cannot_close_another_users_session(): void
    {
        $admin = $this->createTenantWithMember();
        $v1 = $this->member($admin, 'cashier');
        $v2 = $this->member($admin, 'cashier');
        $session = $this->openSession($v1, 0);

        $this->act($v2)
            ->post("/simple/caisse/{$session->id}/fermer", [
                'counted_amount' => 0,
                'note' => null,
            ])
            ->assertForbidden();
    }
}
