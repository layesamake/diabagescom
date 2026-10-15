<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Un caissier ne vend, n'encaisse ni ne ferme que sur la session qu'il a ouverte ;
 * un administrateur (cash.view_all) supervise toutes les sessions du dépôt.
 */
class CashSessionOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_a_cashier_cannot_sell_in_a_colleagues_session(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $owner = $this->member($setup, 'cashier');
        $intruder = $this->member($setup, 'cashier');
        $session = $this->openSession($owner, 50000);

        try {
            $this->sell($intruder, $session, [[$rice->id, '1']], [['cash', 2000]]);
            $this->fail('Selling in a colleague session must be refused.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->count());
        $this->assertSame('10.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);

        // Le propriétaire et l'administrateur (superviseur) peuvent encaisser.
        $this->sell($owner, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $this->assertSame(2, SalesDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_a_cashier_cannot_close_or_record_movements_on_a_colleagues_session(): void
    {
        $setup = $this->createTenantWithMember();
        $owner = $this->member($setup, 'cashier');
        $intruder = $this->member($setup, 'cashier');
        $session = $this->openSession($owner, 50000);

        $this->actingAs($intruder['user'])->post(route('cash.close', $session), ['counted_amount' => 0, 'note' => 'Fermeture'])->assertForbidden();
        $this->assertSame(CashSession::OPEN, CashSession::query()->withoutGlobalScopes()->findOrFail($session->id)->status);

        $this->actingAs($intruder['user'])->post(route('cash.movements.store', $session), [
            'idempotency_key' => (string) Str::uuid(), 'type' => 'income', 'amount' => 1000, 'label' => 'Recette', 'reason' => 'Test',
        ])->assertForbidden();
        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->count());

        $this->enter($setup);
        $closed = app(CashService::class)->close($session, 50000, null, $setup['user']);
        $this->assertSame(CashSession::CLOSED, $closed->status);
    }

    public function test_the_pos_only_lists_the_sessions_the_user_may_operate(): void
    {
        $setup = $this->createTenantWithMember();
        $owner = $this->member($setup, 'cashier');
        $other = $this->member($setup, 'cashier');
        $session = $this->openSession($owner, 0);

        $this->actingAs($other['user'])->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page->has('sessions', 0));
        $this->actingAs($owner['user'])->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page->has('sessions', 1)->where('sessions.0.id', $session->id));
        $this->actingAs($setup['user'])->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page->has('sessions', 1));
    }
}
