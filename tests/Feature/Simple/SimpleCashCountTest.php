<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\CashRegister;
use App\Models\CashSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — comptage de l'argent du tiroir billet par billet (maquette
 * 06b) : le total est recalculé côté serveur et le détail est conservé sur la
 * session, à l'ouverture et à la fermeture.
 */
class SimpleCashCountTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    private function vendeur(): array
    {
        return $this->member($this->createTenantWithMember(), 'cashier');
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    private function register(array $v): CashRegister
    {
        $this->enter($v);

        return CashRegister::query()->where('warehouse_id', $v['warehouse']->id)->where('active', true)->firstOrFail();
    }

    /** 10 000 + 2 × 2 000 + 500 + 3 × 100 = 14 800 F. */
    private function count(): array
    {
        return ['10000' => 1, '2000' => 2, '500' => 1, '100' => 3];
    }

    public function test_opening_with_a_count_stores_the_total_and_the_detail(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);

        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", ['count' => $this->count()])
            ->assertRedirect('/simple/caisse');

        $session = CashSession::query()->where('register_id', $register->id)->where('status', 'open')->firstOrFail();
        $this->assertSame(14800, $session->opening_amount);
        // jsonb ne conserve pas l'ordre des clés : comparaison sans ordre.
        $this->assertEquals(
            ['10000' => 1, '5000' => 0, '2000' => 2, '1000' => 0, '500' => 1, '100' => 3],
            $session->count_details['opening'],
        );
    }

    public function test_the_server_total_wins_over_an_amount_sent_with_the_count(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);

        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", ['count' => $this->count(), 'opening_amount' => 999999])
            ->assertRedirect('/simple/caisse');

        $this->assertSame(14800, CashSession::query()->where('register_id', $register->id)->firstOrFail()->opening_amount);
    }

    public function test_an_unknown_denomination_or_a_negative_count_is_refused(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);

        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", ['count' => ['200' => 3]])
            ->assertInvalid(['count']);
        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", ['count' => ['1000' => -1]])
            ->assertInvalid(['count.1000']);
        $this->act($v)
            ->post("/simple/caisse/{$register->id}/ouvrir", ['count' => ['1000' => 'beaucoup']])
            ->assertInvalid(['count.1000']);

        $this->assertFalse(CashSession::query()->where('register_id', $register->id)->exists());
    }

    public function test_opening_still_accepts_a_plain_amount(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);

        $this->act($v)->post("/simple/caisse/{$register->id}/ouvrir", ['opening_amount' => 25000])->assertRedirect('/simple/caisse');

        $session = CashSession::query()->where('register_id', $register->id)->firstOrFail();
        $this->assertSame(25000, $session->opening_amount);
        $this->assertNull($session->count_details);
    }

    public function test_closing_with_a_count_keeps_both_counts_on_the_session(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);
        $this->act($v)->post("/simple/caisse/{$register->id}/ouvrir", ['count' => $this->count()]);
        $session = CashSession::query()->where('register_id', $register->id)->firstOrFail();

        $this->act($v)
            ->post("/simple/caisse/{$session->id}/fermer", ['count' => $this->count()])
            ->assertRedirect('/simple/caisse');

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertSame(14800, $session->counted_amount);
        $this->assertSame(0, $session->difference);
        $this->assertSame(1, $session->count_details['opening']['10000']);
        $this->assertEquals(['10000' => 1, '5000' => 0, '2000' => 2, '1000' => 0, '500' => 1, '100' => 3], $session->count_details['closing']);
    }

    public function test_a_shortage_found_by_the_count_is_recorded_with_its_reason(): void
    {
        $v = $this->vendeur();
        $register = $this->register($v);
        $this->act($v)->post("/simple/caisse/{$register->id}/ouvrir", ['count' => $this->count()]);
        $session = CashSession::query()->where('register_id', $register->id)->firstOrFail();

        // Il manque 4 800 F : 10 000 F comptés au lieu de 14 800 F attendus.
        $this->act($v)
            ->post("/simple/caisse/{$session->id}/fermer", ['count' => ['10000' => 1], 'note' => 'Monnaie rendue à un client'])
            ->assertRedirect('/simple/caisse');

        $session->refresh();
        $this->assertSame(10000, $session->counted_amount);
        $this->assertSame(14800, $session->expected_amount);
        $this->assertSame(-4800, $session->difference);
        $this->assertSame('Monnaie rendue à un client', $session->closing_note);
    }

    public function test_closing_still_accepts_a_plain_counted_amount(): void
    {
        $v = $this->vendeur();
        $session = $this->openSession($v, 0);

        $this->act($v)->post("/simple/caisse/{$session->id}/fermer", ['counted_amount' => 0, 'note' => null])->assertRedirect('/simple/caisse');

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNull($session->count_details);
    }
}
