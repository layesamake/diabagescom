<?php

namespace Tests\Feature\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Critère de fin d'EPIC-03 : un article est recherchable et scannable dans la bonne
 * entreprise uniquement, et ne peut pas être dupliqué par code-barres.
 */
class CatalogIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(array $setup, string $sku, string $barcode): Product
    {
        $unitId = app(TenantContext::class)->runAs($setup['tenant'], fn () => Unit::query()->where('code', 'U')->value('id'));

        $this->actingAs($setup['user'])->post(route('products.store'), [
            'sku' => $sku, 'name' => "Article {$sku}", 'type' => 'stockable', 'unit_id' => $unitId,
            'sale_price' => 1000, 'barcode' => $barcode,
        ])->assertSessionHasNoErrors();

        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->where('sku', $sku)->sole());
    }

    public function test_two_companies_can_use_the_same_sku_and_barcode(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->createProduct($a, 'SAVON', '6111234567895');
        $this->createProduct($b, 'SAVON', '6111234567895');

        $this->assertDatabaseCount('products', 2);
    }

    public function test_scanning_only_finds_products_of_the_current_company(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $this->createProduct($b, 'SECRET', '6111234567895');

        $this->actingAs($a['user'])->getJson(route('products.lookup', ['code' => '6111234567895']))->assertNotFound();
        $this->actingAs($a['user'])->getJson(route('products.lookup', ['code' => 'SECRET']))->assertNotFound();
    }

    public function test_search_never_returns_another_companys_products(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $this->createProduct($b, 'SECRET', '6111234567895');

        $this->actingAs($a['user'])->get(route('products.index', ['q' => 'SECRET']))
            ->assertInertia(fn ($page) => $page->count('products.data', 0));
    }

    public function test_another_companys_product_cannot_be_opened_or_modified(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $foreign = $this->createProduct($b, 'SECRET', '6111234567895');

        $this->actingAs($a['user'])->get(route('products.edit', $foreign->id))->assertNotFound();
        $this->actingAs($a['user'])->put(route('products.update', $foreign->id), ['name' => 'Piraté'])->assertNotFound();
        $this->actingAs($a['user'])->post(route('products.archive', $foreign->id))->assertNotFound();
        $this->actingAs($a['user'])->post(route('products.barcodes.store', $foreign->id), ['barcode' => '4006381333931'])->assertNotFound();
    }

    public function test_products_cannot_reference_another_companys_unit(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $foreignUnit = app(TenantContext::class)->runAs($b['tenant'], fn () => Unit::query()->where('code', 'U')->value('id'));

        $this->actingAs($a['user'])->post(route('products.store'), [
            'sku' => 'X1', 'name' => 'Test', 'type' => 'stockable', 'unit_id' => $foreignUnit, 'sale_price' => 100,
        ])->assertSessionHasErrors('unit_id');
    }

    public function test_another_companys_customer_cannot_be_modified(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $this->actingAs($b['user'])->post(route('customers.store'), ['name' => 'Client secret']);
        $foreign = app(TenantContext::class)->runAs($b['tenant'], fn () => Customer::query()->sole());

        $this->actingAs($a['user'])->put(route('customers.update', $foreign->id), ['name' => 'Piraté'])->assertNotFound();
        $this->actingAs($a['user'])->get(route('customers.index'))->assertInertia(fn ($page) => $page->count('parties.data', 0));
    }
}
