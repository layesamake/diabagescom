<?php

namespace Tests\Feature\Simple;

use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use Database\Factories\TenantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SimpleProductCreateTest extends TestCase
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

    public function test_the_simple_product_form_exposes_only_the_usable_catalog_fields(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');

        $this->act($manager)
            ->get('/simple/articles/nouveau')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/NouvelArticle')
                ->has('categories')
                ->has('units'));
    }

    public function test_the_simple_product_form_returns_404_when_the_feature_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');

        $this->act($manager)->get('/simple/articles/nouveau')->assertNotFound();
    }

    public function test_a_stock_manager_creates_a_stockable_product_with_an_internal_sku(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');
        $this->enter($manager);
        $unit = Unit::query()->where('code', 'U')->firstOrFail();
        $category = new ProductCategory;
        $category->name = 'Épicerie';
        $category->save();

        $this->act($manager)
            ->post('/simple/articles', [
                'name' => 'Huile d’arachide 1 L',
                'sale_price' => 2500,
                'unit_id' => $unit->id,
                'category_id' => $category->id,
            ])
            ->assertRedirect('/simple/articles/nouveau')
            ->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $this->assertSame('ART-00001', $product->sku);
        $this->assertSame('Huile d’arachide 1 L', $product->name);
        $this->assertSame('stockable', $product->type);
        $this->assertSame(2500, $product->sale_price);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertNotNull($product->tax_rate_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.created', 'entity_id' => $product->id]);
    }

    public function test_a_cashier_cannot_open_or_submit_the_simple_product_form(): void
    {
        $tenant = $this->createTenantWithMember();
        $cashier = $this->member($tenant, 'cashier');

        $this->act($cashier)->get('/simple/articles/nouveau')->assertForbidden();
        $this->act($cashier)->post('/simple/articles', [])->assertForbidden();
    }

    public function test_a_category_from_another_tenant_is_rejected(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');
        $this->enter($manager);
        $unit = Unit::query()->where('code', 'U')->firstOrFail();
        $other = TenantFactory::new()->create();
        $otherCategory = app(TenantContext::class)->runAs($other, function (): ProductCategory {
            $category = new ProductCategory;
            $category->name = 'Autre entreprise';
            $category->save();

            return $category;
        });

        $this->act($manager)
            ->post('/simple/articles', [
                'name' => 'Savon',
                'sale_price' => 500,
                'unit_id' => $unit->id,
                'category_id' => $otherCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('products', 0);
    }
}
