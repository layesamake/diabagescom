<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function enter(array $setup): void
    {
        app(TenantContext::class)->set($setup['tenant'], $setup['membership']->load('role', 'tenant'), $setup['warehouse']);
    }

    private function unitId(array $setup, string $code = 'U'): string
    {
        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Unit::query()->where('code', $code)->value('id'));
    }

    private function payload(array $setup, array $overrides = []): array
    {
        return [
            'sku' => 'RIZ25',
            'name' => 'Riz parfumé 25 kg',
            'type' => 'stockable',
            'unit_id' => $this->unitId($setup),
            'sale_price' => '17 500',
            'barcode' => '6111234567895',
            ...$overrides,
        ];
    }

    private function product(array $setup): Product
    {
        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->with('barcodes')->firstOrFail());
    }

    public function test_admin_creates_a_product_with_default_tax_and_barcode(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup))->assertSessionHasNoErrors();

        $product = $this->product($setup);
        $this->assertSame(17500, $product->sale_price);
        $this->assertSame('6111234567895', $product->barcodes->sole()->code);
        $this->assertTrue($product->barcodes->sole()->is_primary);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.created', 'entity_id' => $product->id]);
    }

    public function test_decimal_prices_are_refused(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])
            ->post(route('products.store'), $this->payload($setup, ['sale_price' => '17500.50']))
            ->assertSessionHasErrors('sale_price');
    }

    public function test_a_wrong_barcode_check_digit_is_refused(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])
            ->post(route('products.store'), $this->payload($setup, ['barcode' => '6111234567893']))
            ->assertSessionHasErrors('barcode');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_barcode_cannot_be_given_to_two_products(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));

        $this->actingAs($setup['user'])
            ->post(route('products.store'), $this->payload($setup, ['sku' => 'RIZ50', 'name' => 'Riz 50 kg']))
            ->assertSessionHasErrors('barcode');

        $this->assertDatabaseCount('products', 1);
    }

    public function test_sku_is_unique_regardless_of_case(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));

        $this->actingAs($setup['user'])
            ->post(route('products.store'), $this->payload($setup, ['sku' => 'riz25', 'barcode' => null]))
            ->assertSessionHasErrors('sku');
    }

    public function test_internal_barcodes_are_generated_sequentially_and_unique(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup, ['barcode' => null, 'generate_internal_barcode' => '1']));
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup, ['sku' => 'HUILE', 'name' => 'Huile', 'barcode' => null, 'generate_internal_barcode' => '1']));

        $codes = app(TenantContext::class)->runAs($setup['tenant'], fn () => ProductBarcode::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['2000000000015', '2000000000022'], $codes);
    }

    public function test_price_changes_require_the_price_override_permission(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $product = $this->product($setup);

        // Un membre sans catalog.price_override (le service revérifie, indépendamment de l'écran).
        $this->enter($setup);
        $cashier = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ProductService::class)->update($product, [
            ...$product->only(['sku', 'name', 'type', 'unit_id', 'tax_rate_id']),
            'sale_price' => 18000,
        ], $product->version, $cashier);
    }

    public function test_a_concurrent_modification_is_not_overwritten(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $product = $this->product($setup);
        $update = fn (string $name) => [
            ...$product->only(['sku', 'type', 'unit_id', 'tax_rate_id', 'sale_price']),
            'name' => $name,
            'version' => 1,
        ];

        $this->actingAs($setup['user'])->put(route('products.update', $product), $update('Premier'))->assertSessionHasNoErrors();
        $this->actingAs($setup['user'])->put(route('products.update', $product), $update('Second'))->assertSessionHasErrors('version');

        $this->assertSame('Premier', $this->product($setup)->name);
    }

    public function test_price_changes_are_audited_with_before_and_after(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $product = $this->product($setup);

        $this->actingAs($setup['user'])->put(route('products.update', $product), [
            ...$product->only(['sku', 'name', 'type', 'unit_id', 'tax_rate_id']),
            'sale_price' => 18000,
            'version' => 1,
        ])->assertSessionHasNoErrors();

        $event = AuditEvent::query()->where('action', 'product.updated')->sole();
        $this->assertSame(['sale_price' => 17500], $event->before);
        $this->assertSame(['sale_price' => 18000], $event->after);
    }

    public function test_cashiers_can_view_but_not_create_products(): void
    {
        $setup = $this->createTenantWithMember('cashier');

        $this->actingAs($setup['user'])->get(route('products.index'))->assertOk();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup))->assertForbidden();
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup, ['name' => 'Café Touba Thiès']));

        $this->actingAs($setup['user'])->get(route('products.index', ['q' => 'cafe touba THIES']))
            ->assertInertia(fn ($page) => $page->count('products.data', 1));
    }

    public function test_lookup_finds_a_product_by_scanned_barcode(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));

        $this->actingAs($setup['user'])->getJson(route('products.lookup', ['code' => ' 6111234567895 ']))
            ->assertOk()
            ->assertJsonPath('product.sku', 'RIZ25')
            ->assertJsonPath('product.tax_rate_basis_points', 1800);

        $this->actingAs($setup['user'])->getJson(route('products.lookup', ['code' => '0000000000000']))->assertNotFound();
    }

    public function test_product_images_are_private_and_served_through_signed_urls(): void
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $product = $this->product($setup);

        $this->actingAs($setup['user'])->post(route('products.image.store', $product), [
            'image' => UploadedFile::fake()->image('riz.png', 400, 400),
        ])->assertSessionHasNoErrors();

        $product = $this->product($setup);
        Storage::disk('local')->assertExists($product->image_path);
        $this->assertStringStartsWith("tenants/{$setup['tenant']->id}/products/", $product->image_path);

        $this->actingAs($setup['user'])->get(route('products.image', $product))->assertForbidden();
        $this->actingAs($setup['user'])->get(URL::temporarySignedRoute('products.image', now()->addMinute(), ['product' => $product->id]))->assertOk();
    }

    public function test_non_image_uploads_are_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));

        $this->actingAs($setup['user'])->post(route('products.image.store', $this->product($setup)), [
            'image' => UploadedFile::fake()->create('script.php.png', 10, 'application/x-php'),
        ])->assertSessionHasErrors('image');
    }

    public function test_admin_duplicates_a_product_with_a_new_sku_and_name_suffix(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $original = $this->product($setup);

        $response = $this->actingAs($setup['user'])->post(route('products.duplicate', $original));
        $response->assertSessionHasNoErrors();

        $copy = app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->where('id', '!=', $original->id)->with('barcodes')->sole());
        $response->assertRedirect(route('products.edit', $copy));

        $this->assertSame('RIZ25-COPIE', $copy->sku);
        $this->assertSame('Riz parfumé 25 kg (copie)', $copy->name);
        $this->assertSame($original->type, $copy->type);
        $this->assertSame($original->unit_id, $copy->unit_id);
        $this->assertSame($original->tax_rate_id, $copy->tax_rate_id);
        $this->assertSame($original->sale_price, $copy->sale_price);
        // Les codes-barres ne sont jamais recopiés : ils désignent un seul article.
        $this->assertCount(0, $copy->barcodes);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.duplicated', 'entity_id' => $copy->id]);
    }

    public function test_duplicating_twice_keeps_skus_unique(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $original = $this->product($setup);

        $this->actingAs($setup['user'])->post(route('products.duplicate', $original))->assertSessionHasNoErrors();
        $this->actingAs($setup['user'])->post(route('products.duplicate', $original))->assertSessionHasNoErrors();

        $skus = app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(['RIZ25', 'RIZ25-COPIE', 'RIZ25-COPIE-2'], $skus);
    }

    public function test_duplicating_copies_the_image_file(): void
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $original = $this->product($setup);
        $this->actingAs($setup['user'])->post(route('products.image.store', $original), [
            'image' => UploadedFile::fake()->image('riz.png', 400, 400),
        ]);
        $original = $this->product($setup);

        $this->actingAs($setup['user'])->post(route('products.duplicate', $original))->assertSessionHasNoErrors();

        $copy = app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->where('id', '!=', $original->id)->sole());
        $this->assertNotNull($copy->image_path);
        $this->assertNotSame($original->image_path, $copy->image_path);
        Storage::disk('local')->assertExists($copy->image_path);
    }

    public function test_cashiers_cannot_duplicate_products(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.store'), $this->payload($setup));
        $original = $this->product($setup);

        $cashier = User::factory()->create();
        \Database\Factories\TenantFactory::addMember($setup['tenant'], $cashier, 'cashier');

        $this->actingAs($cashier)->post(route('products.duplicate', $original))->assertForbidden();
    }
}
