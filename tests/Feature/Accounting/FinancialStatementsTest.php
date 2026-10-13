<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\FinancialStatements;
use App\Domain\Accounting\Ledger;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** Un petit commerce : capital, achats, stock, ventes, encaissement, frais, immobilisation. */
    private function business(): array
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        $ledger = app(Ledger::class);
        $post = fn (string $label, array $lines) => $ledger->post('OD', CarbonImmutable::today(), $label, $lines, $setup['user']);

        $post('Capital', [EntryLine::debit('521', 1000000, 'x'), EntryLine::credit('101', 1000000, 'x')]);
        $post('Achats', [EntryLine::debit('601', 400000, 'x'), EntryLine::debit('4452', 72000, 'x'), EntryLine::credit('401', 472000, 'x')]);
        $post('Stock final', [EntryLine::debit('311', 100000, 'x'), EntryLine::credit('6031', 100000, 'x')]);
        $post('Ventes', [EntryLine::debit('411', 708000, 'x'), EntryLine::credit('701', 600000, 'x'), EntryLine::credit('4431', 108000, 'x')]);
        $post('Encaissement', [EntryLine::debit('571', 300000, 'x'), EntryLine::credit('411', 300000, 'x')]);
        $post('Loyer', [EntryLine::debit('622', 50000, 'x'), EntryLine::credit('571', 50000, 'x')]);
        $post('Salaires', [EntryLine::debit('661', 80000, 'x'), EntryLine::credit('571', 80000, 'x')]);
        $post('Frais bancaires', [EntryLine::debit('631', 5000, 'x'), EntryLine::credit('521', 5000, 'x')]);
        $post('Matériel', [EntryLine::debit('244', 200000, 'x'), EntryLine::credit('521', 200000, 'x')]);
        $post('Dotation', [EntryLine::debit('658', 10000, 'x'), EntryLine::credit('284', 10000, 'x')]);

        return $setup;
    }

    /** @return array<string, int> libellé => montant */
    private function amounts(array $statement): array
    {
        $out = [];

        foreach ($statement['tables'] as $table) {
            foreach ($table['rows'] as $row) {
                $out[$row['label']] = $row['amount'];
            }
        }

        return $out;
    }

    public function test_the_income_statement_follows_syscohada_intermediate_balances(): void
    {
        $this->business();
        $today = CarbonImmutable::today();

        $statement = app(FinancialStatements::class)->incomeStatement($today->startOfYear(), $today);
        $a = $this->amounts($statement);

        $this->assertSame(600000, $a['Ventes de marchandises']);
        $this->assertSame(-400000, $a['Achats de marchandises']);
        $this->assertSame(100000, $a['Variation de stocks de marchandises']); // stock final en hausse : produit
        $this->assertSame(300000, $a['MARGE COMMERCIALE']);
        $this->assertSame(-55000, $a['Services extérieurs']); // 622 + 631
        $this->assertSame(-80000, $a['Charges de personnel']);
        $this->assertSame(-10000, $a['Autres charges']);
        $this->assertSame(155000, $a['RÉSULTAT D’EXPLOITATION']);
        $this->assertSame(155000, $a['RÉSULTAT NET']);
        $this->assertSame(155000, $statement['result']);
    }

    public function test_the_balance_sheet_balances_and_carries_the_current_result(): void
    {
        $this->business();

        $sheet = app(FinancialStatements::class)->balanceSheet(CarbonImmutable::today());
        $this->assertNotNull($sheet);
        $a = $this->amounts($sheet);

        $this->assertSame(200000, $a['Immobilisations (valeur brute)']);
        $this->assertSame(-10000, $a['Amortissements et dépréciations']);
        $this->assertSame(190000, $a['Actif immobilisé net']);
        $this->assertSame(100000, $a['Stocks']);
        $this->assertSame(480000, $a['Créances et emplois assimilés']); // clients 408 000 + TVA récupérable 72 000
        $this->assertSame(965000, $a['Trésorerie-Actif']);
        $this->assertSame(1735000, $a['TOTAL ACTIF']);

        $this->assertSame(1000000, $a['Capital']);
        $this->assertSame(155000, $a['Résultat net de l’exercice']);
        $this->assertSame(1155000, $a['Capitaux propres']);
        $this->assertSame(472000, $a['Fournisseurs d’exploitation']);
        $this->assertSame(108000, $a['Dettes fiscales et sociales']); // TVA facturée
        $this->assertSame(1735000, $a['TOTAL PASSIF']);

        $this->assertTrue($sheet['balanced']);
        $this->assertSame(0, $sheet['difference']);
    }

    public function test_a_debit_balance_in_a_liability_account_goes_to_assets_and_still_balances(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        // Avance versée à un fournisseur (409) et client créditeur (419) : sens inverse de leur nature.
        app(Ledger::class)->post('OD', CarbonImmutable::today(), 'Avances', [
            EntryLine::debit('409', 50000, 'x'), EntryLine::debit('571', 20000, 'x'), EntryLine::credit('419', 70000, 'x'),
        ], $setup['user']);

        $a = $this->amounts(app(FinancialStatements::class)->balanceSheet(CarbonImmutable::today()) ?? []);
        $this->assertSame(50000, $a['Créances et emplois assimilés']);
        $this->assertSame(70000, $a['Autres dettes']);
        $this->assertSame($a['TOTAL ACTIF'], $a['TOTAL PASSIF']);
    }

    public function test_dates_outside_any_fiscal_year_have_no_balance_sheet(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());

        $this->assertNull(app(FinancialStatements::class)->balanceSheet(CarbonImmutable::parse('2000-01-01')));
    }

    public function test_pages_and_audited_csv_export_and_permissions(): void
    {
        $setup = $this->business();
        $today = now()->toDateString();

        $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'balance_sheet', 'to' => $today]))->assertInertia(fn (Assert $p) => $p
            ->component('accounting/Reports')
            ->where('report', 'balance_sheet')
            ->where('data.balanced', true)
            ->has('data.tables', 2));
        $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'income_statement', 'from' => now()->startOfYear()->toDateString(), 'to' => $today]))
            ->assertInertia(fn (Assert $p) => $p->where('data.result', 155000));

        $csv = $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'balance_sheet', 'to' => $today, 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('"TOTAL ACTIF";;1735000', $csv->streamedContent());
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'accounting.exported')->exists());

        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('accounting.reports', ['report' => 'balance_sheet']))->assertForbidden();
    }
}
