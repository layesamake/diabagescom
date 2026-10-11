<?php

namespace Tests\Feature\Backup;

use App\Domain\Backup\TenantExporter;
use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\TenantExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;
use ZipArchive;

/**
 * Sauvegarde d'entreprise : archive complète et vérifiable, limitée à
 * l'entreprise, réservée à l'administrateur, auditée et purgée à expiration.
 */
class TenantExportTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_every_tenant_table_is_either_exported_or_deliberately_excluded(): void
    {
        $tables = DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'tenant_id')
            ->pluck('table_name')->sort()->values()->all();

        $this->assertSame($tables, collect([...TenantExporter::TABLES, ...TenantExporter::EXCLUDED])->sort()->values()->all(),
            'A new tenant table must be added to TenantExporter::TABLES (or EXCLUDED with a reason).');
    }

    public function test_an_admin_gets_a_complete_archive_of_their_company_only(): void
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 4000]]);

        $other = $this->createTenantWithMember();
        $this->makeProduct($other, 'AUTRE');

        $this->actingAs($setup['user'])->post(route('backup.store'))->assertRedirect();

        // File synchrone en test : l'archive est prête immédiatement.
        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame(TenantExport::READY, $export->status);
        $this->assertSame(1, $export->summary['products']);
        $this->assertSame(1, $export->summary['sales_documents']);

        $response = $this->get(route('backup.download', $export))->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->path)));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(TenantExporter::FORMAT, $manifest['format']);
        $this->assertSame($setup['tenant']->id, $manifest['tenant']['id']);

        // Chaque fichier correspond à l'empreinte annoncée par le manifeste.
        foreach ($manifest['files'] as $name => $hash) {
            $this->assertSame($hash, hash('sha256', (string) $zip->getFromName($name)), $name);
        }

        $products = (string) $zip->getFromName('data/products.jsonl');
        $this->assertStringContainsString($rice->id, $products);
        $this->assertStringNotContainsString('AUTRE', $products);
        $this->assertStringContainsString($invoice->number, (string) $zip->getFromName('lisible/ventes.csv'));
        $this->assertFalse($zip->locateName('data/offline_devices.jsonl'));
        $this->assertFalse($zip->locateName('data/subscriptions.jsonl'));
        $zip->close();

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'backup.export_downloaded')->count());
    }

    public function test_only_admins_of_the_company_can_back_up_or_download(): void
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('backup.index'))->assertForbidden();
        $this->actingAs($cashier['user'])->post(route('backup.store'))->assertForbidden();

        $this->actingAs($setup['user'])->post(route('backup.store'));
        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->sole();

        $other = $this->createTenantWithMember();
        $this->actingAs($other['user'])->get(route('backup.download', $export))->assertNotFound();
    }

    public function test_expired_archives_are_deleted_from_the_server(): void
    {
        Storage::fake('local');
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('backup.store'));
        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->sole();
        $path = $export->path;
        Storage::disk('local')->assertExists($path);

        $this->travel(TenantExport::KEEP_DAYS + 1)->days();
        $this->artisan('diaba:backups:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(TenantExport::EXPIRED, $export->refresh()->status);
    }
}
