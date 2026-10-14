<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Recherche globale (Ctrl+K) et vue d'ensemble des paramètres.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_guests_cannot_search(): void
    {
        $this->getJson(route('search.index', ['q' => 'riz']))->assertUnauthorized();
    }

    public function test_a_short_query_returns_nothing(): void
    {
        $setup = $this->createTenantWithMember();
        $this->makeProduct($setup);

        $this->actingAs($setup['user'])->getJson(route('search.index', ['q' => 'r']))->assertOk()
            ->assertExactJson(['products' => [], 'customers' => [], 'suppliers' => [], 'documents' => []]);
    }

    public function test_it_finds_products_parties_and_documents_of_the_company_only(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ');
        $this->makeCustomer($setup);
        $this->makeSupplier($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [['cash', 2000]]);

        // Une autre entreprise a des données qui se ressemblent : elles ne doivent jamais sortir.
        $other = $this->createTenantWithMember();
        $this->makeProduct($other, 'RIZ');
        $this->makeCustomer($other);

        $this->actingAs($setup['user']);

        $this->getJson(route('search.index', ['q' => 'riz']))->assertOk()
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.label', 'Article RIZ')->assertJsonPath('products.0.detail', 'RIZ');
        $this->getJson(route('search.index', ['q' => 'ndiay']))->assertOk()
            ->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.label', 'Client Ndiaye');
        $this->getJson(route('search.index', ['q' => 'sow']))->assertOk()
            ->assertJsonCount(1, 'suppliers')->assertJsonPath('suppliers.0.label', 'Fournisseur Sow');
        $this->getJson(route('search.index', ['q' => (string) $invoice->number]))->assertOk()
            ->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.id', $invoice->id)->assertJsonPath('documents.0.type', 'invoice');
    }

    public function test_each_group_follows_the_permissions_of_the_role(): void
    {
        $setup = $this->createTenantWithMember();
        $this->makeSupplier($setup);
        $cashier = $this->member($setup, 'cashier');
        $purchaser = $this->member($setup, 'purchaser');

        // Le vendeur ne consulte pas les fournisseurs ; le responsable achats si.
        $this->actingAs($cashier['user'])->getJson(route('search.index', ['q' => 'sow']))->assertOk()->assertJsonCount(0, 'suppliers');
        $this->actingAs($purchaser['user'])->getJson(route('search.index', ['q' => 'sow']))->assertOk()->assertJsonCount(1, 'suppliers');
    }

    public function test_the_settings_overview_renders_for_a_member_and_requires_a_login(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));

        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('settings.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Index'));
    }
}
