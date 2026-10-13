<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Barcode;
use App\Domain\Catalog\BarcodeSvg;
use App\Domain\Catalog\ProductService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_ean13_and_code128_match_reference_encodings(): void
    {
        // Séquences recoupées avec une implémentation indépendante (python-barcode).
        $this->assertSame('10100011010100111010111101111010001001011001101010100001010000101000010111010010000101100110101', BarcodeSvg::ean13('4006381333931'));
        $this->assertSame('110100100001100010100010110010000110010100001100101000010001111010110010100001100011101011', BarcodeSvg::code128('Hello'));

        // 95 modules, gardes de début, centre et fin.
        $ean = BarcodeSvg::ean13('2000000000015');
        $this->assertSame(95, strlen($ean));
        $this->assertSame('101', substr($ean, 0, 3));
        $this->assertSame('01010', substr($ean, 45, 5));
        $this->assertSame('101', substr($ean, -3));

        // Code 128 : se termine par le motif d'arrêt (13 modules).
        $this->assertStringEndsWith('1100011101011', BarcodeSvg::code128('A1'));
    }

    public function test_invalid_codes_are_refused(): void
    {
        foreach (['12345', '40063813339AB', ''] as $bad) {
            try {
                BarcodeSvg::ean13($bad);
                $this->fail("EAN-13 « {$bad} » aurait dû être refusé.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['', "tab\t", 'accentué é'] as $bad) {
            try {
                BarcodeSvg::code128($bad);
                $this->fail('Code 128 refusé attendu.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_symbology_renders_an_svg(): void
    {
        $this->assertStringContainsString('<rect', BarcodeSvg::render('4006381333931', Barcode::EAN13));
        $this->assertStringContainsString('<rect', BarcodeSvg::render('2000000000015', Barcode::INTERNAL));
        $this->assertStringContainsString('<rect', BarcodeSvg::render('036000291452', Barcode::UPCA));
        $this->assertStringContainsString('<rect', BarcodeSvg::render('RIZ-25', Barcode::CODE128));

        $qr = BarcodeSvg::render('https://exemple.sn/riz', Barcode::QR);
        $this->assertStringStartsWith('<svg', $qr);
        $this->assertStringNotContainsString('<?xml', $qr);
    }

    private function product(array $setup, string $sku, ?string $barcode): Product
    {
        $this->enter($setup);
        $unit = Unit::query()->where('code', 'U')->value('id');
        $product = app(ProductService::class)->create(['sku' => $sku, 'name' => "Article {$sku}", 'type' => 'stockable', 'unit_id' => $unit, 'sale_price' => 2500, 'barcode' => $barcode], $setup['user']);

        return $product;
    }

    public function test_the_label_page_prints_copies_skips_products_without_barcode_and_stays_in_the_company(): void
    {
        $setup = $this->createTenantWithMember();
        $with = $this->product($setup, 'RIZ', '6111234567895');
        $without = $this->product($setup, 'SANS', null);
        $other = $this->createTenantWithMember();
        $foreign = $this->product($other, 'ETRANGER', '4006381333931');
        app(TenantContext::class)->clear();

        $page = $this->actingAs($setup['user'])->get(route('products.labels', ['ids' => [$with->id, $without->id, $foreign->id], 'copies' => 3, 'size' => '70x40', 'layout' => 'roll']));
        $page->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $html = $page->getContent();

        $this->assertSame(3, substr_count($html, 'class="label"'));
        $this->assertStringContainsString('Article RIZ', $html);
        $this->assertStringContainsString('6111234567895', $html);
        $this->assertStringContainsString('2 500 F', $html);
        $this->assertStringContainsString('size: 70mm 40mm', $html);
        $this->assertStringContainsString('Sans code-barres, non imprimé : Article SANS', $html);
        // Un article d'une autre entreprise n'est jamais imprimé, ni même signalé.
        $this->assertStringNotContainsString('ETRANGER', $html);
        $this->assertStringNotContainsString('4006381333931', $html);

        $noPrice = $this->actingAs($setup['user'])->get(route('products.labels', ['ids' => [$with->id], 'price' => 0]))->getContent();
        $this->assertStringNotContainsString('2 500 F', $noPrice);
    }

    public function test_label_requests_are_validated_and_limited_and_need_catalog_access(): void
    {
        $setup = $this->createTenantWithMember();
        $product = $this->product($setup, 'RIZ', '6111234567895');
        app(TenantContext::class)->clear();

        $this->actingAs($setup['user'])->getJson(route('products.labels'))->assertUnprocessable();
        $this->actingAs($setup['user'])->getJson(route('products.labels', ['ids' => ['pas-un-uuid']]))->assertUnprocessable();
        $this->actingAs($setup['user'])->getJson(route('products.labels', ['ids' => [$product->id], 'size' => '999x999']))->assertUnprocessable();
        $this->actingAs($setup['user'])->getJson(route('products.labels', ['ids' => [$product->id], 'copies' => 101]))->assertUnprocessable();

        // 6 articles × 100 exemplaires dépasse le plafond de 500 étiquettes.
        $many = array_map(fn () => (string) Str::uuid(), range(1, 6));
        $this->actingAs($setup['user'])->get(route('products.labels', ['ids' => $many, 'copies' => 100]))->assertStatus(422);

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get(route('products.labels', ['ids' => [$product->id]]))->assertRedirect(route('login'));
    }
}
