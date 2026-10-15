<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Le coût d'achat n'est jamais transmis sans report.margin_view, ni dans les
 * props Inertia du formulaire, ni dans l'API de recherche.
 */
class ProductCostVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_the_seller_does_not_receive_the_cost_in_inertia_props(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $seller = $this->member($setup, 'cashier');

        $this->actingAs($seller['user'])->get(route('products.edit', $rice))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.standard_cost', null)
            ->where('can.cost', false));
    }

    public function test_the_admin_still_sees_and_keeps_the_cost(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);

        $this->actingAs($setup['user'])->get(route('products.edit', $rice))->assertInertia(fn (Assert $page) => $page
            ->where('product.standard_cost', 1000)
            ->where('can.cost', true));
        $this->assertSame(1000, Product::query()->withoutGlobalScopes()->findOrFail($rice->id)->standard_cost);
    }

    public function test_the_search_api_hides_costs_from_a_role_without_margin_permission(): void
    {
        $setup = $this->createTenantWithMember();
        $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $manager = $this->member($setup, 'warehouse_manager');

        $this->actingAs($manager['user'])->getJson(route('products.search', ['q' => 'RIZ']))
            ->assertOk()->assertJsonPath('products.0.standard_cost', null);
        $this->actingAs($setup['user'])->getJson(route('products.search', ['q' => 'RIZ']))
            ->assertOk()->assertJsonPath('products.0.standard_cost', 1000);
    }
}
