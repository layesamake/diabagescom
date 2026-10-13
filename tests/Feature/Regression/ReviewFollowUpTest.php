<?php

namespace Tests\Feature\Regression;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryAttachments;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\FinancialStatements;
use App\Domain\Accounting\Ledger;
use App\Domain\Sales\SalesDocumentPdf;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogImport;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\PurchaseRequest;
use App\Models\TenantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Corrections issues de la revue de code : chaque défaut signalé a son test.
 */
class ReviewFollowUpTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_print_pages_have_no_inline_event_handlers_blocked_by_the_csp(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [['cash', 2000]]);
        app(TenantContext::class)->runAs($setup['tenant'], fn () => DB::table('product_barcodes')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $setup['tenant']->id, 'product_id' => $rice->id,
            'code' => '6111234567895', 'symbology' => 'ean13', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $ticket = $this->actingAs($setup['user'])->get(route('sales.ticket', ['document' => $invoice]))->assertOk()->getContent();
        $labels = $this->actingAs($setup['user'])->get(route('products.labels', ['ids' => [$rice->id]]))->assertOk()->getContent();

        foreach ([$ticket, $labels] as $html) {
            $this->assertStringNotContainsString('onclick=', $html);
            $this->assertStringContainsString('id="print-button"', $html);
            $this->assertStringContainsString("getElementById('print-button')", $html);
        }
    }

    public function test_contacts_sharing_an_email_but_with_distinct_codes_are_not_duplicates(): void
    {
        $setup = $this->createTenantWithMember();
        $csv = "code;nom;email\nCL-1;Boutique A;compta@exemple.sn\nCL-2;Boutique B;compta@exemple.sn\n";

        $response = $this->actingAs($setup['user'])->post(route('customers.import.store'), ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)]);
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $import = app(TenantContext::class)->runAs($setup['tenant'], fn () => CatalogImport::query()->findOrFail($id));

        $this->assertSame([], $import->errors);
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $id))->assertSessionHasNoErrors();
        $this->assertSame(2, app(TenantContext::class)->runAs($setup['tenant'], fn () => Customer::query()->count()));

        // Le même code deux fois reste un doublon.
        $dup = "code;nom\nCL-9;Un\nCL-9;Deux\n";
        $r = $this->actingAs($setup['user'])->post(route('customers.import.store'), ['file' => UploadedFile::fake()->createWithContent('d.csv', $dup)]);
        $dupImport = app(TenantContext::class)->runAs($setup['tenant'], fn () => CatalogImport::query()->findOrFail(basename((string) parse_url((string) $r->headers->get('Location'), PHP_URL_PATH))));
        $this->assertSame([3], collect($dupImport->errors)->pluck('line')->all());
    }

    public function test_the_ticket_skips_branding_work_but_a4_documents_keep_it(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [['cash', 2000]]);
        $renderer = app(SalesDocumentPdf::class);

        $ticket = $renderer->viewData($invoice, $setup['tenant']);
        $this->assertArrayNotHasKey('companyLogo', $ticket);
        $this->assertArrayNotHasKey('companyInfo', $ticket);
        $this->assertArrayHasKey('companyInfo', $renderer->viewData($invoice, $setup['tenant'], withBranding: true));

        // Une seule lecture de la fiche pour tout un document A4.
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'tenant_profiles')) {
                $queries++;
            }
        });
        $renderer->viewData($invoice, $setup['tenant'], withBranding: true);
        $this->assertSame(1, $queries);

        $this->actingAs($setup['user'])->get(route('sales.pdf', ['document' => $invoice, 'format' => 'a4']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($setup['user'])->get(route('sales.pdf', ['document' => $invoice, 'format' => 'receipt']))->assertOk();
    }

    public function test_the_daily_digest_only_looks_at_members_who_asked_for_it(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();
        $this->openSession($setup);
        $this->travel(1)->day();

        $examined = 0;
        DB::listen(function ($query) use (&$examined): void {
            if (str_contains($query->sql, 'cash_sessions')) {
                $examined++;
            }
        });

        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        $this->assertSame(0, $examined, 'Personne n\'a activé le résumé : aucune alerte calculée.');
        Notification::assertNothingSent();

        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => ['cash_to_close' => ['in_app' => true, 'email' => true]]]);
        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        $this->assertGreaterThan(0, $examined);
    }

    public function test_a_failed_attachment_leaves_no_orphan_file(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        $entry = app(Ledger::class)->saveDraft(null, 'OD', CarbonImmutable::today(), 'Test', [EntryLine::debit('521', 10, 'x'), EntryLine::credit('101', 10, 'x')], $setup['user']);
        // Un utilisateur inexistant fait échouer l'insertion en base après l'écriture du fichier.
        $ghost = clone $setup['user'];
        $ghost->id = (string) Str::uuid7();

        try {
            app(EntryAttachments::class)->add($entry, UploadedFile::fake()->createWithContent('p.pdf', "%PDF-1.4\n%%EOF"), $ghost);
            $this->fail('L\'enregistrement aurait dû échouer.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], Storage::disk('local')->allFiles("tenants/{$setup['tenant']->id}/accounting"));
        $this->assertSame(0, DB::table('journal_entry_attachments')->count());
    }

    public function test_an_update_without_maps_url_keeps_the_existing_link(): void
    {
        $setup = $this->createTenantWithMember();
        $base = ['legal_name' => 'Ma Société SARL', 'display_name' => 'Ma Société', 'vat_regime' => 'real'];
        $this->actingAs($setup['user'])->put(route('company.update'), [...$base, 'maps_url' => 'https://maps.app.goo.gl/AbCd1234']);

        $this->actingAs($setup['user'])->put(route('company.update'), [...$base, 'vat_regime' => 'exempt'])->assertSessionHasNoErrors();
        $profile = TenantProfile::query()->findOrFail($setup['tenant']->id);
        $this->assertSame('https://maps.app.goo.gl/AbCd1234', $profile->maps_url);
        $this->assertSame('exempt', $profile->vat_regime);

        // Envoyer un champ vide retire bien le lien.
        $this->actingAs($setup['user'])->put(route('company.update'), [...$base, 'maps_url' => '']);
        $this->assertNull($profile->refresh()->maps_url);
    }

    public function test_the_income_statement_never_spans_two_fiscal_years(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        app(Ledger::class)->post('OD', CarbonImmutable::today(), 'Vente', [EntryLine::debit('571', 500, 'x'), EntryLine::credit('701', 500, 'x')], $setup['user']);

        $from = CarbonImmutable::today()->startOfYear();
        $yearEnd = CarbonImmutable::today()->endOfYear();
        $within = app(FinancialStatements::class)->incomeStatement($from, $yearEnd);
        $beyond = app(FinancialStatements::class)->incomeStatement($from, $yearEnd->addYears(2));

        $this->assertNull($within['clamped_to']);
        $this->assertSame($yearEnd->toDateString(), $beyond['clamped_to']);
        $this->assertSame($within['result'], $beyond['result']);
        $this->assertSame(500, $beyond['result']);
    }

    public function test_exporting_the_ledger_needs_an_account(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'ledger', 'format' => $format]))->assertStatus(422);
        }

        $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'ledger', 'account' => '521', 'format' => 'xlsx']))->assertOk();
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_purchase_requests_of_colleagues_are_hidden_from_stock_managers(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U');
        $cashier = $this->member($setup, 'cashier');
        $manager = $this->member($setup, 'warehouse_manager');
        $purchaser = $this->member($setup, 'purchaser');

        $this->actingAs($cashier['user'])->post(route('purchases.requests.store'), ['warehouse_id' => $setup['warehouse']->id, 'lines' => [['product_id' => $rice->id, 'quantity' => '2']]]);
        $id = app(TenantContext::class)->runAs($setup['tenant'], fn () => PurchaseRequest::query()->value('id'));

        $this->actingAs($manager['user'])->get(route('purchases.requests.show', $id))->assertNotFound();
        $this->actingAs($manager['user'])->get(route('purchases.requests.index'))->assertInertia(fn ($p) => $p->has('requests.data', 0));
        $this->actingAs($purchaser['user'])->get(route('purchases.requests.show', $id))->assertOk();
    }
}
