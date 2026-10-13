<?php

namespace Tests\Feature\Settings;

use App\Domain\Sales\SalesDocumentPdf;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class CompanyDetailsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function html(array $setup, $invoice): string
    {
        $data = app(SalesDocumentPdf::class)->viewData($invoice, $setup['tenant']->refresh(), withBranding: true);

        return view('pdf.sales-document', [...$data, 'width' => 80, 'logo' => public_path('brand/logo-diaba-gescom-horizontal.png')])->render();
    }

    public function test_contacts_legal_mentions_and_names_are_saved_audited_and_printed_on_documents(): void
    {
        $setup = $this->createTenantWithMember();
        $details = [
            'legal_name' => 'Diop et Frères SARL', 'display_name' => 'Diop Distribution', 'vat_regime' => 'real', 'maps_url' => '',
            'phone' => '+221 77 123 45 67', 'email' => 'contact@diop.sn', 'address' => '12 rue Carnot', 'city' => 'Dakar',
            'ninea' => '006123456 2G2', 'rccm' => 'SN-DKR-2024-B-12345', 'document_footer' => 'Paiement à 30 jours. Merci de votre confiance.',
        ];

        $this->actingAs($setup['user'])->put(route('company.update'), $details)->assertRedirect()->assertSessionHasNoErrors();

        $tenant = $setup['tenant']->refresh();
        $this->assertSame('Diop et Frères SARL', $tenant->legal_name);
        $this->assertSame('Diop Distribution', $tenant->display_name);
        $this->assertSame('SN-DKR-2024-B-12345', TenantProfile::query()->findOrFail($tenant->id)->rccm);

        $event = AuditEvent::query()->withoutGlobalScopes()->where('action', 'company.profile_updated')->latest('created_at')->firstOrFail();
        $this->assertSame('Diop Distribution', $event->after['display_name'] ?? null);

        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [['cash', 2000]]);
        $html = $this->html($setup, $invoice);

        foreach (['12 rue Carnot, Dakar', '+221 77 123 45 67 · contact@diop.sn', 'NINEA : 006123456 2G2', 'RCCM : SN-DKR-2024-B-12345', 'Paiement à 30 jours', 'Diop Distribution'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // Champs vidés : plus rien n'est imprimé.
        $this->actingAs($setup['user'])->put(route('company.update'), [...$details, 'ninea' => '', 'rccm' => '', 'document_footer' => '', 'address' => '', 'city' => '', 'phone' => '', 'email' => ''])->assertSessionHasNoErrors();
        $html = $this->html($setup, $invoice);
        $this->assertStringNotContainsString('NINEA', $html);
        $this->assertStringNotContainsString('Paiement à 30 jours', $html);
    }

    public function test_contact_fields_are_validated(): void
    {
        $setup = $this->createTenantWithMember();
        $base = ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real'];

        foreach ([
            ['email' => 'pas-un-email'], ['phone' => 'appelez-moi'], ['ninea' => '<script>'], ['rccm' => 'x; DROP TABLE'],
            ['legal_name' => ''], ['display_name' => 'a'], ['document_footer' => str_repeat('x', 301)],
        ] as $bad) {
            $this->actingAs($setup['user'])->put(route('company.update'), [...$base, ...$bad])->assertSessionHasErrors(array_key_first($bad));
        }
    }

    public function test_support_changes_do_not_erase_the_companys_contacts(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->put(route('company.update'), ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real', 'ninea' => '006123456 2G2', 'phone' => '+221 77 000 00 00']);

        $owner = User::factory()->withTwoFactor()->create();
        $owner->platform_role_id = Role::system('platform', 'super_admin')->id;
        $owner->save();
        $this->actingAs($owner)->put(route('platform.tenants.profile.update', $setup['tenant']->id), ['vat_regime' => 'exempt', 'maps_url' => '', 'reason' => 'Demande du client'])->assertRedirect();

        $profile = TenantProfile::query()->findOrFail($setup['tenant']->id);
        $this->assertSame('exempt', $profile->vat_regime);
        $this->assertSame('006123456 2G2', $profile->ninea);
        $this->assertSame('+221 77 000 00 00', $profile->phone);
        $this->assertSame('Ma Société SARL', $setup['tenant']->refresh()->legal_name);
    }
}
