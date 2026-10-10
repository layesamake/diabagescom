<?php

namespace Tests\Feature\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Photos d'articles et de catégories : ré-encodées, privées, servies par lien signé.
 */
class CatalogPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function in(array $setup, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($setup['tenant'], $callback);
    }

    private function category(array $setup, string $name = 'Épicerie'): ProductCategory
    {
        $this->actingAs($setup['user'])->post(route('catalog.categories.save'), ['name' => $name])->assertRedirect();

        return $this->in($setup, fn () => ProductCategory::query()->where('name', $name)->sole());
    }

    private function product(array $setup): Product
    {
        $unitId = $this->in($setup, fn () => Unit::query()->where('code', 'U')->value('id'));
        $this->actingAs($setup['user'])->post(route('products.store'), [
            'sku' => 'RIZ25', 'name' => 'Riz', 'type' => 'stockable', 'unit_id' => $unitId, 'sale_price' => 100,
        ])->assertRedirect();

        return $this->in($setup, fn () => Product::query()->sole());
    }

    public function test_product_photos_are_reencoded_to_webp_and_resized(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->product($setup);

        $this->actingAs($setup['user'])
            ->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('photo.jpg', 3000, 2000)])
            ->assertSessionHasNoErrors();

        $path = $this->in($setup, fn () => $product->fresh()->image_path);
        $this->assertStringEndsWith('.webp', $path);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame([1200, 800], [$width, $height]);
    }

    public function test_a_disguised_payload_does_not_survive_reencoding(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->product($setup);

        // Une vraie image suivie de code PHP (fichier « polyglotte »).
        $image = UploadedFile::fake()->image('piege.png', 50, 50);
        file_put_contents($image->getRealPath(), '<?php system($_GET["c"]); ?>', FILE_APPEND);

        $this->actingAs($setup['user'])->post(route('products.image.store', $product), ['image' => $image])->assertSessionHasNoErrors();

        $stored = Storage::disk('local')->get($this->in($setup, fn () => $product->fresh()->image_path));
        $this->assertStringNotContainsString('<?php', $stored);
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->product($setup);

        $this->actingAs($setup['user'])->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('a.png')]);
        $first = $this->in($setup, fn () => $product->fresh()->image_path);
        $this->actingAs($setup['user'])->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('b.png')]);

        Storage::disk('local')->assertMissing($first);
    }

    public function test_product_photo_can_be_removed(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->product($setup);
        $this->actingAs($setup['user'])->post(route('products.image.store', $product), ['image' => UploadedFile::fake()->image('a.png')]);
        $path = $this->in($setup, fn () => $product->fresh()->image_path);

        $this->actingAs($setup['user'])->delete(route('products.image.destroy', $product))->assertRedirect();

        $this->assertNull($this->in($setup, fn () => $product->fresh()->image_path));
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.image_removed']);
    }

    public function test_category_photos_are_stored_privately_and_served_signed(): void
    {
        $setup = $this->createTenantWithMember();
        $category = $this->category($setup);

        $this->actingAs($setup['user'])
            ->post(route('catalog.categories.image.store', $category), ['image' => UploadedFile::fake()->image('epicerie.jpg', 800, 600)])
            ->assertSessionHasNoErrors();

        $category = $this->in($setup, fn () => $category->fresh());
        $this->assertStringStartsWith("tenants/{$setup['tenant']->id}/categories/", $category->image_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'category.image_changed', 'entity_id' => $category->id]);

        $this->actingAs($setup['user'])->get(route('catalog.categories.image', $category))->assertForbidden();
        $this->actingAs($setup['user'])
            ->get(URL::temporarySignedRoute('catalog.categories.image', now()->addMinute(), ['category' => $category->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($setup['user'])->get(route('catalog.settings'))
            ->assertInertia(fn ($page) => $page->where('categories.0.image_url', fn ($url) => is_string($url) && str_contains($url, 'signature=')));
    }

    public function test_category_photo_can_be_removed(): void
    {
        $setup = $this->createTenantWithMember();
        $category = $this->category($setup);
        $this->actingAs($setup['user'])->post(route('catalog.categories.image.store', $category), ['image' => UploadedFile::fake()->image('a.png')]);

        $this->actingAs($setup['user'])->delete(route('catalog.categories.image.destroy', $category))->assertRedirect();

        $this->assertNull($this->in($setup, fn () => $category->fresh()->image_path));
    }

    public function test_another_companys_category_photo_is_unreachable(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $foreign = $this->category($b, 'Secret');
        $this->actingAs($b['user'])->post(route('catalog.categories.image.store', $foreign), ['image' => UploadedFile::fake()->image('s.png')]);

        $this->actingAs($a['user'])
            ->get(URL::temporarySignedRoute('catalog.categories.image', now()->addMinute(), ['category' => $foreign->id]))
            ->assertNotFound();
        $this->actingAs($a['user'])
            ->post(route('catalog.categories.image.store', $foreign), ['image' => UploadedFile::fake()->image('x.png')])
            ->assertNotFound();
    }

    public function test_cashiers_cannot_change_photos(): void
    {
        $admin = $this->createTenantWithMember();
        $category = $this->category($admin);
        $cashier = User::factory()->create();
        TenantFactory::addMember($admin['tenant'], $cashier, 'cashier');

        $this->actingAs($cashier)
            ->post(route('catalog.categories.image.store', $category), ['image' => UploadedFile::fake()->image('x.png')])
            ->assertForbidden();
    }

    public function test_non_images_are_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $category = $this->category($setup);

        $this->actingAs($setup['user'])
            ->post(route('catalog.categories.image.store', $category), ['image' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')])
            ->assertSessionHasErrors('image');
    }
}
