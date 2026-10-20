<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
