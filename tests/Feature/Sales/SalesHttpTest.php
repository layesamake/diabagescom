<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesHttpTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_every_new_warehouse_gets_a_cash_register(): void
    {
        $setup = $this->createTenantWithMember();

        $this->assertSame(1, CashRegister::query()->withoutGlobalScopes()->where('warehouse_id', $setup['warehouse']->id)->count());
    }

    public function test_pages_render_for_an_admin(): void
    {
        $setup = $this->createTenantWithMember();
        $this->openSession($setup);
        $user = $setup['user'];

        $this->actingAs($user)->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page
            ->component('sales/Pos')->has('sessions', 1)->where('warehouse.id', $setup['warehouse']->id));
        $this->actingAs($user)->get(route('sales.index', ['type' => 'quote']))->assertInertia(fn (Assert $page) => $page->component('sales/Documents'));
        $this->actingAs($user)->get(route('sales.create', ['type' => 'quote']))->assertInertia(fn (Assert $page) => $page->component('sales/DocumentForm'));
        $this->actingAs($user)->get(route('cash.index'))->assertInertia(fn (Assert $page) => $page->component('cash/Registers')->has('registers', 1));
        $this->actingAs($user)->get(route('purchases.orders.index'))->assertInertia(fn (Assert $page) => $page->component('purchases/Orders'));
        $this->actingAs($user)->get(route('purchases.orders.create'))->assertInertia(fn (Assert $page) => $page->component('purchases/OrderForm'));
        $this->actingAs($user)->get(route('purchases.invoices.index'))->assertInertia(fn (Assert $page) => $page->component('purchases/Invoices'));
        $this->actingAs($user)->get(route('purchases.invoices.create'))->assertInertia(fn (Assert $page) => $page->component('purchases/InvoiceForm'));
    }

    public function test_frequent_products_are_the_best_sellers_of_the_warehouse(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ');
        $oil = $this->makeProduct($setup, 'HUILE');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000], [$setup['warehouse']->id, $oil->id, '20', 1000]]);
        $session = $this->openSession($setup, 0);

        // Sans historique : articles par ordre alphabétique.
        $this->actingAs($setup['user'])->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page
            ->where('frequent.0.sku', 'HUILE')->where('frequent.0.available', '20'));

        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $this->sell($setup, $session, [[$oil->id, '1']], [['cash', 2000]]);

        $this->actingAs($setup['user'])->get(route('pos.create'))->assertInertia(fn (Assert $page) => $page
            ->where('frequent.0.sku', 'RIZ')->where('frequent.1.sku', 'HUILE')->has('frequent', 2));
    }

    public function test_a_counter_sale_over_http_is_idempotent_and_prints(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $setup['warehouse']->id,
            'cash_session_id' => $session->id,
            'lines' => [['product_id' => $rice->id, 'quantity' => '2', 'unit_price' => 2000, 'discount' => 0]],
            'tenders' => [['method' => 'cash', 'amount' => 4000]],
        ];

        $first = $this->actingAs($setup['user'])->post(route('pos.store'), $payload);
        $sale = SalesDocument::query()->withoutGlobalScopes()->firstOrFail();
        $first->assertRedirect(route('sales.show', ['document' => $sale, 'receipt' => 1]));
        $this->actingAs($setup['user'])->post(route('pos.store'), $payload)->assertRedirect();

        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());

        $this->actingAs($setup['user'])->get(route('sales.show', ['document' => $sale, 'receipt' => 1]))
            ->assertInertia(fn (Assert $page) => $page->component('sales/DocumentShow')->where('fromPos', true)->where('document.number', $sale->number));

        foreach ([[], ['format' => 'receipt']] as $query) {
            $pdf = $this->actingAs($setup['user'])->get(route('sales.pdf', ['document' => $sale, ...$query]));
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        }
    }

    public function test_business_errors_come_back_as_a_readable_message(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $session = $this->openSession($setup);

        $this->actingAs($setup['user'])->from(route('pos.create'))->post(route('pos.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $setup['warehouse']->id,
            'cash_session_id' => $session->id,
            'lines' => [['product_id' => $rice->id, 'quantity' => '1']],
            'tenders' => [['method' => 'cash', 'amount' => 2000]],
        ])->assertRedirect(route('pos.create'))->assertSessionHasErrors(['business' => 'Stock insuffisant pour « Article RIZ » : 0 disponible(s), 1 demandé(s).']);
    }

    public function test_another_company_cannot_see_or_touch_sales_cash_or_purchases(): void
    {
        $owner = $this->createTenantWithMember();
        $rice = $this->makeProduct($owner);
        $this->move($owner, 'initial', [[$owner['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($owner);
        $sale = $this->sell($owner, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $intruder = $this->createTenantWithMember();

        $this->actingAs($intruder['user'])->get(route('sales.show', $sale))->assertNotFound();
        $this->actingAs($intruder['user'])->get(route('sales.pdf', $sale))->assertNotFound();
        $this->actingAs($intruder['user'])->get(route('cash.sessions.show', $session))->assertNotFound();
        $this->actingAs($intruder['user'])->post(route('sales.payments.store', $sale), [
            'idempotency_key' => (string) Str::uuid(), 'tenders' => [['method' => 'wave', 'amount' => 1]],
        ])->assertNotFound();
        $this->actingAs($intruder['user'])->post(route('cash.close', $session), ['counted_amount' => 0])->assertNotFound();
    }

    public function test_warehouse_scoping_and_role_permissions(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $other = $this->makeWarehouse($setup, 'THS');
        $this->move($setup, 'initial', [[$other->id, $rice->id, '5', 1000]]);
        $this->enter($setup);
        $register = CashRegister::query()->where('warehouse_id', $other->id)->firstOrFail();
        $session = app(CashService::class)->open($register, 0, $setup['user']);
        $sale = $this->sell([...$setup, 'warehouse' => $other], $session, [[$rice->id, '1']], [['cash', 2000]]);

        // Caissier affecté au dépôt principal seulement.
        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('sales.show', $sale))->assertNotFound();
        $this->actingAs($cashier['user'])->get(route('sales.index'))->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->actingAs($cashier['user'])->get(route('purchases.orders.index'))->assertForbidden();
        $this->actingAs($cashier['user'])->post(route('sales.credit_notes.store', $sale), [
            'idempotency_key' => (string) Str::uuid(), 'reason' => 'Retour', 'lines' => [['line_id' => (string) Str::uuid(), 'quantity' => '1']],
        ])->assertForbidden();

        // Gestionnaire de dépôt : réception oui, caisse et ventes à crédit non.
        $manager = $this->member($setup, 'warehouse_manager');
        $this->actingAs($manager['user'])->get(route('cash.index'))->assertForbidden();
        $this->actingAs($manager['user'])->get(route('pos.create'))->assertForbidden();

        // Caissier : ne voit que ses propres sessions.
        $own = $this->openSession($cashier);
        $this->actingAs($cashier['user'])->get(route('cash.sessions.show', $own))->assertOk();
        $this->actingAs($cashier['user'])->get(route('cash.sessions.show', $session))->assertNotFound();
    }

    public function test_read_only_subscription_blocks_sales(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $this->travel(11)->days();

        $this->actingAs($setup['user'])->post(route('pos.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $setup['warehouse']->id,
            'cash_session_id' => $session->id,
            'lines' => [['product_id' => $rice->id, 'quantity' => '1']],
            'tenders' => [['method' => 'cash', 'amount' => 2000]],
        ])->assertRedirect();

        $this->assertSame(0, SalesDocument::query()->withoutGlobalScopes()->count(), 'Read-only: nothing is sold.');
    }
}
