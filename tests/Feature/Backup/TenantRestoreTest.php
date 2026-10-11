<?php

namespace Tests\Feature\Backup;

use App\Domain\Accounting\AccountingPoster;
use App\Domain\Accounting\AccountingSetup;
use App\Domain\Backup\InvalidBackup;
use App\Domain\Backup\TenantExporter;
use App\Domain\Backup\TenantRestorer;
use App\Domain\Cash\CashService;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\TenantRestore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;
use ZipArchive;

/**
 * Restauration d'une sauvegarde dans une nouvelle entreprise : copie fidèle,
 * nouveaux identifiants, entreprise d'origine intacte, protections de la base
 * respectées ; archives modifiées, étrangères ou d'un non-membre refusées.
 */
class TenantRestoreTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * @return array{0: array<string, mixed>, 1: string} entreprise d'origine, chemin de l'archive
     */
    private function backedUpCompany(): array
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $context = app(TenantContext::class);

        $category = function (string $name, ?string $parentId) use ($context, $setup): ProductCategory {
            return $context->runAs($setup['tenant'], function () use ($name, $parentId): ProductCategory {
                $category = new ProductCategory;
                $category->forceFill(['name' => $name, 'parent_id' => $parentId])->save();

                return $category;
            });
        };
        $category('Céréales', $category('Alimentation', null)->id);

        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear(), CarbonImmutable::today()->subMonth());

        $session = $this->openSession($setup, 5000);
        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 4000], ['wave', 2000]]);
        $credit = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 1000]], customerId: $customer->id);
        $this->enter($setup);
        app(PaymentService::class)->receive($credit, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], (string) Str::uuid());
        app(CreditNoteService::class)->create($sale, [['line_id' => $sale->lines()->value('id'), 'quantity' => '1', 'restock' => true]], 'Retour', $setup['user'], (string) Str::uuid());

        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '5', 'unit_cost' => 900]], null, null, $setup['user'], (string) Str::uuid());
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'F-9', 'goods_receipt_id' => $receipt->id, 'lines' => $invoices->linesFromReceipt($receipt)], $setup['user']), $setup['user']);
        app(SupplierPaymentService::class)->pay($invoice, 'cash', 1000, $session->id, null, $setup['user'], (string) Str::uuid());

        $cash = app(CashService::class);
        $cash->close($session, $cash->drawerBalance($session), null, $setup['user']);
        $this->assertGreaterThan(3, app(AccountingPoster::class)->postPending()['posted']);

        Storage::disk('local')->put("tenants/{$setup['tenant']->id}/products/riz.webp", 'image');
        DB::table('products')->where('id', $rice->id)->update(['image_path' => "tenants/{$setup['tenant']->id}/products/riz.webp"]);

        $export = $context->runAs($setup['tenant'], function () use ($setup): TenantExport {
            $export = new TenantExport;
            $export->forceFill(['status' => TenantExport::PENDING, 'created_by' => $setup['user']->id])->save();

            return $export;
        });
        $result = $context->runAs($setup['tenant'], fn () => app(TenantExporter::class)->build($setup['tenant'], $export));

        return [$setup, Storage::disk('local')->path($result['path'])];
    }

    /**
     * @return array<string, int>
     */
    private function countsFor(string $tenantId): array
    {
        return collect(TenantExporter::TABLES)->reject(fn ($t) => in_array($t, ['tenant_memberships', 'warehouse_memberships', 'audit_events'], true))
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $tenantId)->count()])->all();
    }

    public function test_a_backup_is_restored_faithfully_into_a_new_company(): void
    {
        [$setup, $zip] = $this->backedUpCompany();
        $before = $this->countsFor($setup['tenant']->id);
        app(TenantContext::class)->clear();

        $result = app(TenantRestorer::class)->restore($zip, $setup['user'], Plan::query()->where('code', 'business_accounting')->firstOrFail());
        $restored = $result['tenant'];

        $this->assertNotSame($setup['tenant']->id, $restored->id);
        $this->assertSame($before, $this->countsFor($restored->id), 'Same number of rows in every table.');
        $this->assertSame($before, $this->countsFor($setup['tenant']->id), 'The original company is untouched.');

        // Nouveaux identifiants partout, mêmes montants et mêmes états.
        $this->assertSame(0, DB::table('products')->where('tenant_id', $restored->id)
            ->whereIn('id', DB::table('products')->where('tenant_id', $setup['tenant']->id)->select('id'))->count());
        foreach (['sales_documents' => 'status', 'supplier_invoices' => 'status', 'payments' => 'amount', 'journal_entries' => 'status'] as $table => $column) {
            $original = DB::table($table)->where('tenant_id', $setup['tenant']->id)->orderBy('number')->get(['number', $column])->toArray();
            $copy = DB::table($table)->where('tenant_id', $restored->id)->orderBy('number')->get(['number', $column])->toArray();
            $this->assertEquals($original, $copy, $table);
        }
        $this->assertSame((int) DB::table('sales_documents')->where('tenant_id', $setup['tenant']->id)->sum('amount_paid'),
            (int) DB::table('sales_documents')->where('tenant_id', $restored->id)->sum('amount_paid'));
        $this->assertSame(DB::table('accounting_periods')->where('tenant_id', $setup['tenant']->id)->orderBy('starts_on')->pluck('status')->all(),
            DB::table('accounting_periods')->where('tenant_id', $restored->id)->orderBy('starts_on')->pluck('status')->all());

        // Liens internes réécrits vers la copie : avoir → facture, catégorie → parent, photo.
        $creditNote = DB::table('sales_documents')->where('tenant_id', $restored->id)->where('type', 'credit_note')->sole();
        $this->assertSame($restored->id, DB::table('sales_documents')->where('id', $creditNote->source_document_id)->value('tenant_id'));
        $child = DB::table('product_categories')->where('tenant_id', $restored->id)->where('name', 'Céréales')->sole();
        $this->assertSame($restored->id, DB::table('product_categories')->where('id', $child->parent_id)->value('tenant_id'));
        $image = DB::table('products')->where('tenant_id', $restored->id)->value('image_path');
        $this->assertStringStartsWith("tenants/{$restored->id}/", $image);
        Storage::disk('local')->assertExists($image);

        // Accès : seul l'utilisateur qui restaure, comme administrateur.
        $this->assertSame([$setup['user']->id], DB::table('tenant_memberships')->where('tenant_id', $restored->id)->pluck('user_id')->all());

        // Les contrôles d'intégrité de l'exercice de restauration passent sur l'ensemble.
        $sql = collect(explode("\n", (string) file_get_contents(base_path('docker/backup/integrity-checks.sql'))))
            ->reject(fn (string $line) => str_starts_with(ltrim($line), '\\'))->implode("\n");
        DB::unprepared($sql);
        $this->assertSame(0, (int) DB::table('drill_checks')->sum('anomalies'));
    }

    public function test_a_modified_archive_is_refused_and_nothing_is_created(): void
    {
        [$setup, $zip] = $this->backedUpCompany();
        $tenants = Tenant::query()->count();

        $archive = new ZipArchive;
        $archive->open($zip);
        $archive->addFromString('data/customers.jsonl', '{"id":"forged"}'."\n");
        $archive->close();

        try {
            app(TenantRestorer::class)->restore($zip, $setup['user'], Plan::query()->firstOrFail());
            $this->fail('A modified archive must be refused.');
        } catch (InvalidBackup $e) {
            $this->assertStringContainsString('abîmée', $e->getMessage());
        }

        $archive->open($zip);
        $archive->addFromString('manifest.json', '{"format":"diaba-tenant-export","version":1,"files":{}}');
        $archive->close();
        $this->expectExceptionObject(InvalidBackup::because('signature'));

        try {
            app(TenantRestorer::class)->restore($zip, $setup['user'], Plan::query()->firstOrFail());
        } finally {
            $this->assertSame($tenants, Tenant::query()->count());
        }
    }

    public function test_the_restore_page_checks_the_upload_then_restores_in_the_background(): void
    {
        [$setup, $zip] = $this->backedUpCompany();
        app(TenantContext::class)->clear();
        $this->actingAs($setup['user']);

        $this->get(route('restore.create'))->assertOk();

        // Fichier qui n'est pas une sauvegarde : refusé dès l'envoi.
        $this->post(route('restore.store'), ['backup' => UploadedFile::fake()->createWithContent('photo.zip', 'pas une archive')])
            ->assertSessionHasErrors('backup');
        $this->assertSame(0, TenantRestore::query()->count());

        $this->post(route('restore.store'), ['backup' => new UploadedFile($zip, 'sauvegarde.zip', 'application/zip', null, true)])
            ->assertSessionHasNoErrors();

        // File synchrone en test : la restauration est déjà faite et le fichier envoyé supprimé.
        $restore = TenantRestore::query()->sole();
        $this->assertSame(TenantRestore::DONE, $restore->status, (string) $restore->error);
        $this->assertNull($restore->path);
        $this->assertSame([], Storage::disk('local')->files('restores'));
        $this->post(route('context.tenant'), ['tenant_id' => $restore->tenant_id])->assertRedirect(route('dashboard'));
    }

    public function test_someone_who_was_not_a_member_cannot_restore_the_archive(): void
    {
        [, $zip] = $this->backedUpCompany();

        $this->expectExceptionObject(InvalidBackup::because('not_a_member'));
        app(TenantRestorer::class)->restore($zip, User::factory()->create(), Plan::query()->firstOrFail());
    }
}
