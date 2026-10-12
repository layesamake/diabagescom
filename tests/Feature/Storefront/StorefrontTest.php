<?php

namespace Tests\Feature\Storefront;

use App\Domain\Storefront\StorefrontCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Storefront\PublicStorefrontController;
use App\Http\Requests\Storefront\UpdateStorefrontRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Storefront;
use App\Models\StorefrontProduct;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private function publish(Tenant $tenant, string $slug): Product
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $slug): Product {
            $category = ProductCategory::query()->create(['name' => 'Informatique']);
            $product = Product::query()->forceCreate([
                'sku' => 'PC-001', 'name' => 'Ordinateur', 'type' => 'stockable',
                'unit_id' => Unit::query()->where('code', 'U')->value('id'),
                'tax_rate_id' => TaxRate::query()->firstOrFail()->id,
                'category_id' => $category->id, 'sale_price' => 250000,
            ]);
            $storefront = Storefront::query()->create([
                'tenant_id' => $tenant->id, 'slug' => $slug, 'enabled' => true,
            ]);
            StorefrontProduct::query()->create([
                'tenant_id' => $tenant->id, 'storefront_id' => $storefront->id,
                'product_id' => $product->id, 'visible' => true,
            ]);

            return $product;
        });
    }

    public function test_guest_can_read_products_with_their_units_and_categories(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->publish($setup['tenant'], 'tsi-informatique');
        app(TenantContext::class)->clear();

        $this->get(route('storefront.show', 'tsi-informatique'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('products.data', 1)
                ->where('products.data.0.id', $product->id)
                ->where('products.data.0.unit', 'U')
                ->where('products.data.0.category', 'Informatique'));

        $this->assertFalse(app(TenantContext::class)->has());

        $this->get(route('storefront.show', ['slug' => 'tsi-informatique', 'q' => 'ordinateur']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('products.data', 1)
                ->where('products.data.0.id', $product->id));

        $this->assertFalse(app(TenantContext::class)->has());
    }

    public function test_public_catalog_uses_its_owner_and_restores_the_previous_context(): void
    {
        $owner = $this->createTenantWithMember();
        $visitor = $this->createTenantWithMember();
        $product = $this->publish($owner['tenant'], 'tsi-informatique');
        $this->publish($visitor['tenant'], 'autre-boutique');
        $context = app(TenantContext::class);
        $context->set($visitor['tenant'], $visitor['membership'], $visitor['warehouse']);
        $request = Request::create(route('storefront.show', 'tsi-informatique'));
        $request->headers->set('X-Inertia', 'true');

        $response = app(PublicStorefrontController::class)->show($request, 'tsi-informatique', app(FeatureAccess::class));
        $data = json_decode($response->toResponse($request)->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $data['props']['products']['data']);
        $this->assertSame($product->id, $data['props']['products']['data'][0]['id']);
        $this->assertSame('U', $data['props']['products']['data'][0]['unit']);
        $this->assertSame('Informatique', $data['props']['products']['data'][0]['category']);
        $this->assertSame($visitor['tenant'], $context->tenant());
        $this->assertSame($visitor['membership'], $context->membership());
        $this->assertSame($visitor['warehouse'], $context->warehouse());
    }

    public function test_slug_validation_accepts_own_slug_and_rejects_another_storefront_slug(): void
    {
        $owner = $this->createTenantWithMember();
        $other = $this->createTenantWithMember();
        $this->publish($owner['tenant'], 'tsi-informatique');
        $this->publish($other['tenant'], 'autre-boutique');
        app(TenantContext::class)->set($owner['tenant']);
        $rules = (new UpdateStorefrontRequest)->rules();

        $this->assertTrue(Validator::make(['slug' => 'tsi-informatique'], $rules)->passes());
        $this->assertTrue(Validator::make(['slug' => 'autre-boutique'], $rules)->errors()->has('slug'));
    }

    public function test_purchased_products_become_unpublished_drafts_and_stay_hidden_from_visitors(): void
    {
        $setup = $this->createTenantWithMember();
        Plan::query()->update(['online_store_enabled' => true]);
        app(FeatureAccess::class)->forget($setup['tenant']);
        $product = $this->publish($setup['tenant'], 'tsi-informatique');
        $other = app(TenantContext::class)->runAs($setup['tenant'], fn (): Product => Product::query()->forceCreate([
            'sku' => 'PC-002', 'name' => 'Écran', 'type' => 'stockable',
            'unit_id' => Unit::query()->where('code', 'U')->value('id'),
            'tax_rate_id' => TaxRate::query()->firstOrFail()->id, 'sale_price' => 90000,
        ]));

        app(StorefrontCatalog::class)->draftProducts($setup['tenant'], [$product->id, $other->id, $other->id]);
        app(TenantContext::class)->clear();

        $drafts = StorefrontProduct::query()->where('product_id', $other->id)->get();
        $this->assertCount(1, $drafts);
        $this->assertFalse($drafts->first()->visible);
        $this->assertTrue(StorefrontProduct::query()->where('product_id', $product->id)->firstOrFail()->visible);
        $this->get(route('storefront.show', 'tsi-informatique'))
            ->assertInertia(fn ($page) => $page->count('products.data', 1)->where('products.data.0.id', $product->id));
    }

    public function test_nothing_is_drafted_when_the_plan_has_no_online_store(): void
    {
        $setup = $this->createTenantWithMember();
        Plan::query()->update(['online_store_enabled' => false]);
        app(FeatureAccess::class)->forget($setup['tenant']);
        $product = app(TenantContext::class)->runAs($setup['tenant'], fn (): Product => Product::query()->forceCreate([
            'sku' => 'PC-003', 'name' => 'Clavier', 'type' => 'stockable',
            'unit_id' => Unit::query()->where('code', 'U')->value('id'),
            'tax_rate_id' => TaxRate::query()->firstOrFail()->id, 'sale_price' => 15000,
        ]));

        app(StorefrontCatalog::class)->draftProducts($setup['tenant'], [$product->id]);

        $this->assertSame(0, StorefrontProduct::query()->count());
        $this->assertSame(0, Storefront::query()->count());
    }
}
