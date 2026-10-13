<?php

namespace Tests\Feature\Settings;

use App\Domain\Organization\CompanyProfile;
use App\Domain\Sales\SalesDocumentPdf;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\Storefront;
use App\Models\TenantProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_page_shows_defaults_and_saves_regime_and_google_maps_link(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('company.edit'))->assertInertia(fn (Assert $p) => $p
            ->component('settings/Company')
            ->where('profile.vat_regime', 'real')
            ->where('profile.maps_url', null)
            ->where('profile.images.logo', null)
            ->where('can.manage', true));

        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'cgu', 'maps_url' => 'https://maps.app.goo.gl/AbCd1234'])->assertRedirect();

        $this->actingAs($setup['user'])->get(route('company.edit'))->assertInertia(fn (Assert $p) => $p
            ->where('profile.vat_regime', 'cgu')->where('profile.maps_url', 'https://maps.app.goo.gl/AbCd1234'));
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'company.profile_updated')->exists());
    }

    public function test_only_google_maps_share_links_are_accepted(): void
    {
        $setup = $this->createTenantWithMember();

        foreach ([
            '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>',
            'https://evil.example/maps/place',
            'http://www.google.com/maps/place/x',
            'https://www.google.com.evil.example/maps/x',
            'https://www.google.com/search?q=maps',
            'javascript:alert(1)',
        ] as $bad) {
            $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real', 'maps_url' => $bad])->assertSessionHasErrors('maps_url');
        }

        foreach (['https://www.google.com/maps/place/Dakar', 'https://maps.google.com/?q=14.69,-17.44', 'https://goo.gl/maps/abc', 'https://www.google.sn/maps/@14.7,-17.4,15z'] as $good) {
            $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real', 'maps_url' => $good])->assertSessionHasNoErrors();
        }

        // Vider le champ retire la localisation.
        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real', 'maps_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->maps_url);

        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'bogus'])->assertSessionHasErrors('vat_regime');
    }

    public function test_images_are_reencoded_stored_per_company_and_replaceable(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('logo.png', 300, 200)])->assertRedirect();

        $profile = TenantProfile::query()->findOrFail($setup['tenant']->id);
        $this->assertStringStartsWith("tenants/{$setup['tenant']->id}/profile/", (string) $profile->logo_path);
        $this->assertStringEndsWith('.webp', (string) $profile->logo_path);
        Storage::disk('local')->assertExists((string) $profile->logo_path);

        $url = $this->actingAs($setup['user'])->get(route('company.edit'))->viewData('page')['props']['profile']['images']['logo'];
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/webp');

        // Remplacement : l'ancien fichier disparaît.
        $old = (string) $profile->logo_path;
        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('nouveau.jpg', 100, 100)])->assertRedirect();
        Storage::disk('local')->assertMissing($old);

        // Un fichier qui n'est pas une image est refusé.
        $this->actingAs($setup['user'])->post(route('company.images.store', 'stamp'), ['image' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')])->assertSessionHasErrors('image');
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->stamp_path);

        $this->actingAs($setup['user'])->post(route('company.images.store', 'unknown'), ['image' => UploadedFile::fake()->image('a.png')])->assertNotFound();

        $this->actingAs($setup['user'])->delete(route('company.images.destroy', 'logo'))->assertRedirect();
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->logo_path);
    }

    public function test_only_the_administrator_can_change_the_profile_and_companies_are_isolated(): void
    {
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($cashier['user'])->get(route('company.edit'))->assertInertia(fn (Assert $p) => $p->where('can.manage', false));
        $this->actingAs($cashier['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'exempt'])->assertForbidden();
        $this->actingAs($cashier['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('l.png')])->assertForbidden();
        $this->actingAs($cashier['user'])->delete(route('company.images.destroy', 'logo'))->assertForbidden();

        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('l.png')]);
        $other = $this->createTenantWithMember();
        $this->actingAs($other['user'])->get(route('company.edit'))->assertInertia(fn (Assert $p) => $p->where('profile.images.logo', null));
    }

    public function test_the_public_shop_shows_logo_optional_cover_and_map_link(): void
    {
        $setup = $this->createTenantWithMember();
        app(TenantContext::class)->runAs($setup['tenant'], fn () => Storefront::query()->create(['tenant_id' => $setup['tenant']->id, 'slug' => 'ma-boutique', 'enabled' => true]));
        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real', 'maps_url' => 'https://maps.app.goo.gl/AbCd1234']);
        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('l.png', 200, 200)]);
        app(TenantContext::class)->clear();
        auth()->logout();
        $this->app['auth']->forgetGuards();

        // Logo et lien Maps, sans devanture.
        $this->get(route('storefront.show', 'ma-boutique'))->assertInertia(fn (Assert $p) => $p
            ->where('storefront.maps_url', 'https://maps.app.goo.gl/AbCd1234')
            ->where('storefront.cover_url', null)
            ->whereType('storefront.logo_url', 'string'));
        $this->get(route('storefront.logo', 'ma-boutique'))->assertOk()->assertHeader('content-type', 'image/webp');
        $this->get(route('storefront.cover', 'ma-boutique'))->assertNotFound();

        // Boutique inconnue ou désactivée : rien n'est servi.
        $this->get(route('storefront.logo', 'inconnue'))->assertNotFound();
    }

    public function test_documents_carry_the_logo_the_stamp_and_the_vat_regime(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('l.png', 200, 100)]);
        $this->actingAs($setup['user'])->post(route('company.images.store', 'stamp'), ['image' => UploadedFile::fake()->image('s.png', 200, 100)]);
        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'cgu', 'maps_url' => '']);

        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [['cash', 2000]]);

        $data = app(SalesDocumentPdf::class)->viewData($invoice, $setup['tenant'], withBranding: true);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $data['companyLogo']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $data['companyStamp']);

        $html = view('pdf.sales-document', [...$data, 'width' => 80, 'logo' => public_path('brand/logo-diaba-gescom-horizontal.png')])->render();
        $this->assertStringContainsString('Contribution globale unique', $html);
        $this->assertStringContainsString('Cachet et signature', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);

        $this->get(route('sales.pdf', ['document' => $invoice, 'format' => 'a4']))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Sans image, le document reste correct (régime par défaut : réel).
        $this->actingAs($setup['user'])->delete(route('company.images.destroy', 'logo'));
        $this->actingAs($setup['user'])->delete(route('company.images.destroy', 'stamp'));
        $this->assertNull(app(CompanyProfile::class)->pngDataUri($setup['tenant'], 'logo'));
        $this->get(route('sales.pdf', ['document' => $invoice, 'format' => 'a4']))->assertOk();
    }
}
