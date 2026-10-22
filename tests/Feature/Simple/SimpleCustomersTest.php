<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 5 : encaissement des dettes clients.
 *
 * L'écran reste une façade : les dettes proviennent des factures validées,
 * calculées par le métier de vente existant.
 */
class SimpleCustomersTest extends TestCase
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

    public function test_the_simple_customers_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $setup = $this->member($this->createTenantWithMember(), 'cashier');

        $this->act($setup)->get('/simple/clients')->assertNotFound();
    }

    public function test_the_simple_customer_sheet_shows_accessible_unpaid_invoices(): void
    {
        $setup = $this->member($this->createTenantWithMember(), 'cashier');
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $customer->forceFill(['name' => 'Aminata Diallo'])->save();
        $product = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $product->id, '5', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$product->id, '2']], [], $customer->id);

        $this->act($setup)
            ->get("/simple/clients/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Client')
                ->where('customer.id', $customer->id)
                ->where('total_due', 4000)
                ->has('invoices', 1)
                ->where('invoices.0.id', $invoice->id)
                ->where('invoices.0.balance', 4000)
                ->has('invoices.0.sessions', 1));
    }

    public function test_a_cashier_can_record_a_partial_payment_from_the_simple_customer_sheet(): void
    {
        $setup = $this->member($this->createTenantWithMember(), 'cashier');
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $product = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $product->id, '5', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$product->id, '2']], [], $customer->id);

        $this->act($setup)
            ->post("/simple/clients/{$customer->id}/factures/{$invoice->id}/paiement", [
                'idempotency_key' => (string) Str::uuid7(),
                'cash_session_id' => $session->id,
                'method' => 'cash',
                'amount' => 1000,
            ])
            ->assertRedirect("/simple/clients/{$customer->id}");

        $this->assertSame(1000, $invoice->fresh()->amount_paid);
        $this->assertTrue(Payment::query()->where('sales_document_id', $invoice->id)->where('amount', 1000)->exists());
    }

    public function test_the_simple_customers_page_hides_debts_from_unassigned_warehouses(): void
    {
        $admin = $this->createTenantWithMember();
        $cashier = $this->member($admin, 'cashier');
        $otherWarehouse = $this->makeWarehouse($admin, 'AUTRE');
        $otherSetup = [...$admin, 'warehouse' => $otherWarehouse];
        $customer = $this->makeCustomer($admin, creditLimit: 100000);
        $customer->forceFill(['name' => 'Dette autre dépôt'])->save();
        $product = $this->makeProduct($admin, 'MIL', 'U', 1000);
        $this->move($otherSetup, 'initial', [[$otherWarehouse->id, $product->id, '5', 1000]]);
        $session = $this->openSession($otherSetup, 0);
        $this->sell($otherSetup, $session, [[$product->id, '1']], [], $customer->id);

        $this->act($cashier)
            ->get('/simple/clients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total_due', 0)
                ->has('customers', 0));
    }

    public function test_the_simple_customers_page_lists_debtors_by_descending_amount(): void
    {
        $setup = $this->member($this->createTenantWithMember(), 'cashier');
        $first = $this->makeCustomer($setup, creditLimit: 100000);
        $first->forceFill(['name' => 'Aminata Diallo'])->save();
        $second = $this->makeCustomer($setup, creditLimit: 100000);
        $second->forceFill(['name' => 'Boubacar Fall'])->save();
        $product = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $product->id, '10', 1000]]);
        $session = $this->openSession($setup, 0);

        $this->sell($setup, $session, [[$product->id, '1']], [], $first->id);
        $this->sell($setup, $session, [[$product->id, '2']], [], $second->id);

        $this->act($setup)
            ->get('/simple/clients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Clients')
                ->where('total_due', 6000)
                ->has('customers', 2)
                ->where('customers.0.id', $second->id)
                ->where('customers.0.name', 'Boubacar Fall')
                ->where('customers.0.due', 4000)
                ->where('customers.1.id', $first->id)
                ->where('customers.1.due', 2000));
    }
}
