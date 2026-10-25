<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — « Nouveau client » (maquette 08a) : un nom, un téléphone
 * facultatif, créé par le même service que le mode expert. Le client apparaît
 * ensuite dans la liste, sans dette.
 */
class SimpleCustomerCreateTest extends TestCase
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

    private function cashier(): array
    {
        return $this->member($this->createTenantWithMember(), 'cashier');
    }

    public function test_the_form_and_the_creation_return_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $setup = $this->cashier();

        $this->act($setup)->get('/simple/clients/nouveau')->assertNotFound();
        $this->act($setup)->post('/simple/clients', ['name' => 'Awa Ndiaye'])->assertNotFound();
    }

    public function test_a_cashier_sees_the_new_customer_form(): void
    {
        $this->act($this->cashier())
            ->get('/simple/clients/nouveau')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('simple/ClientNouveau'));
    }

    public function test_creating_a_customer_stores_the_trimmed_name_and_phone_with_an_automatic_code(): void
    {
        $setup = $this->cashier();

        $this->act($setup)
            ->post('/simple/clients', ['name' => '  Awa Ndiaye ', 'phone' => '77 123 45 67'])
            ->assertRedirect('/simple/clients');

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('name', 'Awa Ndiaye')
            ->firstOrFail();
        $this->assertSame('77 123 45 67', $customer->phone);
        $this->assertNotEmpty($customer->code);
        $this->assertNull($customer->archived_at);
    }

    public function test_the_phone_is_optional(): void
    {
        $setup = $this->cashier();

        $this->act($setup)->post('/simple/clients', ['name' => 'Moussa Ba', 'phone' => ''])->assertRedirect('/simple/clients');

        $this->assertNull(Customer::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)->where('name', 'Moussa Ba')->firstOrFail()->phone);
    }

    public function test_the_new_customer_appears_in_the_list_without_debt(): void
    {
        $setup = $this->cashier();
        $this->act($setup)->post('/simple/clients', ['name' => 'Awa Ndiaye']);

        $this->act($setup)
            ->get('/simple/clients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Clients')
                ->where('can.create', true)
                ->where('customers', fn ($list) => collect($list)->pluck('name')->doesntContain('Awa Ndiaye'))
                ->where('settled', fn ($list) => collect($list)->pluck('name')->contains('Awa Ndiaye')));
    }

    public function test_a_debtor_is_listed_once_among_the_debtors_and_not_among_the_settled(): void
    {
        $setup = $this->cashier();
        $debtor = $this->makeCustomer($setup, creditLimit: 100000);
        $debtor->forceFill(['name' => 'Aminata Diallo'])->save();
        $product = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $product->id, '5', 1000]]);
        $this->sell($setup, $this->openSession($setup, 0), [[$product->id, '2']], [], $debtor->id);

        $this->act($setup)
            ->get('/simple/clients')
            ->assertInertia(fn ($page) => $page
                ->where('customers', fn ($list) => collect($list)->pluck('id')->contains($debtor->id))
                ->where('settled', fn ($list) => collect($list)->pluck('id')->doesntContain($debtor->id)));
    }

    public function test_archived_customers_and_other_companies_are_not_listed(): void
    {
        $setup = $this->cashier();
        $archived = $this->makeCustomer($setup);
        $archived->forceFill(['name' => 'Ancien Client', 'archived_at' => now()])->save();
        $other = $this->member($this->createTenantWithMember(), 'cashier');
        $foreign = $this->makeCustomer($other);
        $foreign->forceFill(['name' => 'Client Ailleurs'])->save();

        $this->act($setup)
            ->get('/simple/clients')
            ->assertInertia(fn ($page) => $page->where('settled', fn ($list) => collect($list)->pluck('name')
                ->intersect(['Ancien Client', 'Client Ailleurs'])->isEmpty()));
    }

    public function test_the_name_is_required_and_the_phone_must_look_like_a_phone_number(): void
    {
        $setup = $this->cashier();

        $this->act($setup)->post('/simple/clients', ['name' => ''])->assertInvalid(['name']);
        $this->act($setup)->post('/simple/clients', ['name' => 'A'])->assertInvalid(['name']);
        $this->act($setup)->post('/simple/clients', ['name' => 'Awa Ndiaye', 'phone' => 'pas un numéro'])->assertInvalid(['phone']);

        $this->assertFalse(Customer::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->where('name', 'Awa Ndiaye')->exists());
    }

    public function test_a_role_that_cannot_manage_customers_cannot_create_one(): void
    {
        $setup = $this->member($this->createTenantWithMember(), 'accountant');

        $this->act($setup)->get('/simple/clients/nouveau')->assertForbidden();
        $this->act($setup)->post('/simple/clients', ['name' => 'Awa Ndiaye'])->assertForbidden();
        // Il voit la liste, mais sans le bouton « Nouveau client ».
        $this->act($setup)->get('/simple/clients')->assertOk()->assertInertia(fn ($page) => $page->where('can.create', false));
    }
}
