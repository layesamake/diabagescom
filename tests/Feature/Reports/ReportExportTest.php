<?php

namespace Tests\Feature\Reports;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\Ledger;
use App\Domain\Reports\TabularReport;
use App\Domain\Reports\XlsxWriter;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;
use ZipArchive;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** Contenu des fichiers d'un classeur .xlsx. */
    private function unzip(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Le classeur doit être une archive valide.');
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $files;
    }

    private function salesSetup(): array
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $this->sell($setup, $this->openSession($setup), [[$rice->id, '3']], [['cash', 6000]]);

        return $setup;
    }

    public function test_the_workbook_is_valid_numeric_and_never_contains_formulas(): void
    {
        $report = new TabularReport('Rapport <test> & Cie', 'Du 01/01 au 31/01', [
            TabularReport::section('Section', ['Libellé', 'Montant'], [
                ['=HYPERLINK("http://evil.example","clic")', 1234567],
                ['+SUM(A1)', -500],
                ["Texte avec \x01 contrôle", null],
                ['Total', 99],
            ], [3]),
        ]);

        $files = $this->unzip((new XlsxWriter)->build($report, 'Ma Société'));

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertArrayHasKey($part, $files);
            $this->assertNotFalse(simplexml_load_string($files[$part]), "{$part} doit être un XML valide.");
        }

        $sheet = $files['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<v>1234567</v>', $sheet);
        $this->assertStringContainsString('<v>-500</v>', $sheet);
        // Une valeur qui ressemble à une formule reste du texte.
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('Ma Société', $sheet);
        $this->assertStringNotContainsString("\x01", $sheet);
        $this->assertStringContainsString('Rapport &lt;test&gt; &amp; Cie', $sheet);
    }

    public function test_sheet_names_are_valid_and_columns_go_past_z(): void
    {
        $cells = array_map(fn ($i) => "c{$i}", range(1, 28));
        $files = $this->unzip((new XlsxWriter)->build(new TabularReport('Ventes/[2026]: très long titre de feuille dépassant trente et un caractères', '', [
            TabularReport::section(null, $cells, [array_fill(0, 28, 1)]),
        ]), 'X'));

        $this->assertMatchesRegularExpression('/<sheet name="[^\/\[\]:*?\\\\]{1,31}"/u', $files['xl/workbook.xml']);
        $this->assertStringContainsString('r="AB', $files['xl/worksheets/sheet1.xml']); // 28e colonne
    }

    public function test_the_sales_report_exports_to_excel_and_pdf_with_audit_and_scope(): void
    {
        $setup = $this->salesSetup();
        $query = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];

        $xlsx = $this->actingAs($setup['user'])->get(route('reports.index', [...$query, 'format' => 'xlsx']));
        $xlsx->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = $this->unzip($xlsx->getContent())['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<v>6000</v>', $sheet); // chiffre d'affaires TTC
        $this->assertStringContainsString('Marge', $sheet);

        $pdf = $this->actingAs($setup['user'])->get(route('reports.index', [...$query, 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'report.exported')->whereIn('metadata->format', ['xlsx', 'pdf'])->count());

        // Un caissier n'exporte pas, et le classeur d'un autre profil n'expose pas les coûts.
        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('reports.index', [...$query, 'format' => 'xlsx']))->assertForbidden();
        $this->actingAs($cashier['user'])->get(route('reports.index', [...$query, 'format' => 'pdf']))->assertForbidden();
    }

    public function test_accounting_statements_export_to_excel_and_pdf(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        app(Ledger::class)->post('OD', CarbonImmutable::today(), 'Capital', [EntryLine::debit('521', 750000, 'x'), EntryLine::credit('101', 750000, 'x')], $setup['user']);
        $to = now()->toDateString();

        foreach (['balance_sheet', 'income_statement', 'balance'] as $report) {
            $xlsx = $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => $report, 'from' => now()->startOfYear()->toDateString(), 'to' => $to, 'format' => 'xlsx']));
            $xlsx->assertOk();
            $this->unzip($xlsx->getContent());

            $pdf = $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => $report, 'from' => now()->startOfYear()->toDateString(), 'to' => $to, 'format' => 'pdf']));
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $sheet = $this->unzip($this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'balance_sheet', 'to' => $to, 'format' => 'xlsx']))->getContent())['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('TOTAL ACTIF', $sheet);
        $this->assertStringContainsString('<v>750000</v>', $sheet);

        $this->assertGreaterThanOrEqual(6, AuditEvent::query()->withoutGlobalScopes()->where('action', 'accounting.exported')->count());

        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('accounting.reports', ['report' => 'balance_sheet', 'format' => 'xlsx']))->assertForbidden();
    }
}
