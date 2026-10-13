<?php

namespace Tests\Feature\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(string $content, string $name = 'articles.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(array $setup, string $content): CatalogImport
    {
        $response = $this->actingAs($setup['user'])->post(route('products.import.store'), ['file' => $this->csv($content)]);
        $response->assertSessionHasNoErrors();

        // L'import créé est celui de la redirection (jamais « le plus récent » : deux imports
        // dans la même seconde ont la même date).
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        return app(TenantContext::class)->runAs($setup['tenant'], fn () => CatalogImport::query()->findOrFail($id));
    }

    private function products(array $setup)
    {
        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Product::query()->with('barcodes')->orderBy('sku')->get());
    }

    public function test_a_valid_file_is_previewed_then_applied(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, "sku;nom;unite;prix_vente;tva;categorie;code_barres\nRIZ25;Riz parfumé 25 kg;SAC;17 500;18;Épicerie;6111234567895\nHUILE5;Huile 5 L;U;6500;;Épicerie;\n");

        $this->assertSame('previewed', $import->status);
        $this->assertSame(2, $import->rows_valid);
        $this->assertDatabaseCount('products', 0); // L'aperçu n'écrit rien.

        $this->actingAs($setup['user'])->post(route('products.import.apply', $import->id))->assertSessionHasNoErrors();

        $products = $this->products($setup);
        $this->assertSame(['HUILE5', 'RIZ25'], $products->pluck('sku')->all());
        $this->assertSame(17500, $products[1]->sale_price);
        $this->assertSame('6111234567895', $products[1]->barcodes->sole()->code);
        $this->assertSame(1, app(TenantContext::class)->runAs($setup['tenant'], fn () => ProductCategory::query()->count()));
    }

    public function test_errors_are_reported_line_by_line_and_block_the_import(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, "sku;nom;unite;prix_vente;code_barres\nOK1;Valide;U;100;\n;Sans référence;U;100;\nX2;Mauvaise unité;BOITE;100;\nX3;Prix décimal;U;12.5,5;\nX4;Mauvais code;U;100;6111234567893\nOK1;Doublon;U;100;\n");

        $this->assertSame(1, $import->rows_valid);
        $this->assertSame([3, 4, 5, 6, 7], array_column($import->errors, 'line'));

        $this->actingAs($setup['user'])->post(route('products.import.apply', $import->id))->assertSessionHasErrors('import');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_applying_twice_is_refused_and_reimporting_creates_no_duplicates(): void
    {
        $setup = $this->createTenantWithMember();
        $content = "sku;nom;unite;prix_vente\nRIZ25;Riz;SAC;17500\n";

        $first = $this->upload($setup, $content);
        $this->actingAs($setup['user'])->post(route('products.import.apply', $first->id));
        $this->actingAs($setup['user'])->post(route('products.import.apply', $first->id))->assertSessionHasErrors('import');

        $second = $this->upload($setup, $content);
        $this->actingAs($setup['user'])->post(route('products.import.apply', $second->id))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->products($setup));
        $this->assertSame(1, $second->fresh()->summary['unchanged']);
    }

    public function test_existing_products_are_updated_by_sku(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('products.import.apply', $this->upload($setup, "sku;nom;unite;prix_vente\nRIZ25;Riz;SAC;17500\n")->id));

        $this->actingAs($setup['user'])->post(route('products.import.apply', $this->upload($setup, "sku;nom;unite;prix_vente\nriz25;Riz parfumé;SAC;18000\n")->id));

        $product = $this->products($setup)->sole();
        $this->assertSame('Riz parfumé', $product->name);
        $this->assertSame(18000, $product->sale_price);
    }

    public function test_excel_windows_encoding_and_comma_separator_are_supported(): void
    {
        $setup = $this->createTenantWithMember();
        $content = mb_convert_encoding("sku,nom,unite,prix_vente\nCAFE,\"Café Touba, 500 g\",U,2500\n", 'Windows-1252', 'UTF-8');

        $this->actingAs($setup['user'])->post(route('products.import.apply', $this->upload($setup, $content)->id))->assertSessionHasNoErrors();

        $this->assertSame('Café Touba, 500 g', $this->products($setup)->sole()->name);
    }

    public function test_quoted_fields_may_contain_line_breaks(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, "sku;nom;unite;prix_vente;description\nA1;Article;U;100;\"Ligne 1\nLigne 2\"\nA2;Autre;U;200;\n");

        $this->assertSame(2, $import->rows_valid);
        $this->assertSame([], $import->errors);
    }

    public function test_missing_required_columns_are_reported(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('products.import.store'), ['file' => $this->csv("sku;nom\nA;B\n")])
            ->assertSessionHasErrors('file');
    }

    public function test_cashiers_cannot_import(): void
    {
        $setup = $this->createTenantWithMember('cashier');

        $this->actingAs($setup['user'])->post(route('products.import.store'), ['file' => $this->csv("sku;nom;unite;prix_vente\nA;B;U;1\n")])
            ->assertForbidden();
    }
}
