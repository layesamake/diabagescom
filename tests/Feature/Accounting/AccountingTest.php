<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountingPoster;
use App\Domain\Accounting\AccountingReports;
use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\BankReconciliationService;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\FiscalCalendar;
use App\Domain\Accounting\InvalidAccountingOperation;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\YearEndClosing;
use App\Domain\Cash\CashService;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Domain\Purchases\SupplierReturnService;
use App\Domain\Sales\CreditNoteService;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function setUpAccounting(array $setup): void
    {
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear(), CarbonImmutable::today()->subMonth());
    }

    private function runPoster(): array
    {
        return app(AccountingPoster::class)->postPending();
    }

    /**
     * Lignes d'une écriture automatique : [compte => [débit, crédit]].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function entry(string $sourceType, string $sourceId, string $kind): array
    {
        $entry = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_kind', $kind)->firstOrFail();
        $this->assertSame(JournalEntry::POSTED, $entry->status);
        $lines = [];

        foreach ($entry->lines()->with('account')->get() as $line) {
            $lines[$line->account->code] = [($lines[$line->account->code][0] ?? 0) + $line->debit, ($lines[$line->account->code][1] ?? 0) + $line->credit];
        }

        $this->assertSame(array_sum(array_column($lines, 0)), array_sum(array_column($lines, 1)), 'Balanced entry.');
        ksort($lines, SORT_STRING);

        return $lines;
    }

    private function balanceOf(string $code): int
    {
        return app(AccountingReports::class)->balance(ChartAccount::query()->where('code', $code)->value('id'), CarbonImmutable::today());
    }

    public function test_initialization_copies_the_syscohada_template_once(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $this->setUpAccounting($setup);

        $this->assertSame(count(config('syscohada.accounts')), ChartAccount::query()->count());
        $this->assertSame(7, DB::table('accounting_journals')->where('tenant_id', $setup['tenant']->id)->count());
        $this->assertSame(1, FiscalYear::query()->count());
        $this->assertSame(12, AccountingPeriod::query()->count());
        $this->assertSame(count(config('syscohada.mappings')), DB::table('account_mappings')->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_accounting_requires_a_compta_plan(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business', activateAccounting: false);
        $this->enter($setup);

        $this->expectException(InvalidAccountingOperation::class);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
    }

    public function test_a_counter_sale_produces_balanced_sale_cost_and_payment_entries_once(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 10000); // prix 20 000 TTC
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 10000]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 30000], ['wave', 10000]]);
        $this->setUpAccounting($setup);

        $first = $this->runPoster();
        $again = $this->runPoster();

        // 40 000 TTC = 33 898 HT + 6 102 TVA.
        $this->assertSame(['411' => [40000, 0], '4431' => [0, 6102], '701' => [0, 33898]], $this->entry('SalesDocument', $sale->id, 'sale'));
        $this->assertSame(['311' => [0, 20000], '6031' => [20000, 0]], $this->entry('SalesDocument', $sale->id, 'cogs'));
        $payments = $sale->payments()->orderBy('number')->get();
        $this->assertSame(['411' => [0, 30000], '571' => [30000, 0]], $this->entry('Payment', $payments[0]->id, 'payment'));
        $this->assertSame(['411' => [0, 10000], '5213' => [10000, 0]], $this->entry('Payment', $payments[1]->id, 'payment'));
        $this->assertSame(['311' => [100000, 0], '4711' => [0, 100000]], $this->entry('StockOperation', DB::table('stock_operations')->where('type', 'initial')->value('id'), 'stock'));

        $this->assertSame(5, $first['posted']);
        $this->assertSame(0, $again['posted'], 'Replaying the poster creates nothing.');
        $this->assertSame(5, JournalEntry::query()->count());
        $this->assertSame(0, $this->balanceOf('411'), 'The customer account is settled.');
        $this->assertSame(0, app(AccountingPoster::class)->pendingCount());
    }

    public function test_purchases_flow_through_goods_received_not_invoiced_and_supplier_account(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $this->setUpAccounting($setup);
        $this->enter($setup);

        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '10', 'unit_cost' => 1000]], null, null, $setup['user'], (string) Str::uuid());
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'F-1', 'goods_receipt_id' => $receipt->id, 'lines' => [
                ...$invoices->linesFromReceipt($receipt),
                ['product_id' => null, 'label' => 'Transport', 'quantity' => '1', 'unit_cost' => 2000, 'tax_rate_basis_points' => 0],
            ]], $setup['user']), $setup['user']);
        $payment = app(SupplierPaymentService::class)->pay($invoice, 'transfer', 5000, null, 'VIR-9', $setup['user'], (string) Str::uuid());
        $return = app(SupplierReturnService::class)->create($receipt, [$receipt->lines()->value('id') => '2'], 'Avarie', $setup['user'], (string) Str::uuid());

        $this->runPoster();

        $this->assertSame(['311' => [10000, 0], '4081' => [0, 10000]], $this->entry('GoodsReceipt', $receipt->id, 'receipt'));
        $this->assertSame(['401' => [0, 13800], '4081' => [10000, 0], '4452' => [1800, 0], '605' => [2000, 0]], $this->entry('SupplierInvoice', $invoice->id, 'purchase'));
        $this->assertSame(['401' => [5000, 0], '521' => [0, 5000]], $this->entry('Payment', $payment->id, 'payment'));
        $this->assertSame(['311' => [0, 2000], '401' => [2360, 0], '4452' => [0, 360]], $this->entry('SupplierReturn', $return->id, 'supplier_return'));

        // Dette fournisseur en compta = reste dû sur la facture.
        $this->assertSame(-$invoice->refresh()->balanceDue(), $this->balanceOf('401'));
        $this->assertSame(0, $this->balanceOf('4081'));
    }

    public function test_credit_notes_cash_expenses_differences_and_stock_adjustments(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup, 5000);
        $sale = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 4000]]);
        $this->enter($setup);
        $credit = app(CreditNoteService::class)->create($sale, [['line_id' => $sale->lines()->value('id'), 'quantity' => '1', 'restock' => true]], 'Retour', $setup['user'], (string) Str::uuid());
        $expense = app(CashService::class)->recordOther($session->id, 'expense', 1500, 'Carburant', 'Livraison', $setup['user'], (string) Str::uuid());
        app(CashService::class)->close($session, 7000, 'Pièce perdue', $setup['user']); // attendu 7 500
        $loss = $this->move($setup, 'adjustment', [[$setup['warehouse']->id, $rice->id, '-1']], reason: 'Casse');
        $this->setUpAccounting($setup);

        $this->runPoster();

        $this->assertSame(['411' => [0, 2000], '4431' => [305, 0], '701' => [1695, 0]], $this->entry('SalesDocument', $credit->id, 'credit_note'));
        $this->assertSame(['311' => [1000, 0], '6031' => [0, 1000]], $this->entry('SalesDocument', $credit->id, 'cogs_return'));
        $this->assertSame(['571' => [0, 1500], '638' => [1500, 0]], $this->entry('CashMovement', $expense->id, 'cash'));
        $this->assertSame(['571' => [0, 500], '658' => [500, 0]], $this->entry('CashSession', $session->id, 'cash_difference'));
        $this->assertSame(['311' => [0, 1000], '6031' => [1000, 0]], $this->entry('StockOperation', $loss->id, 'stock'));
    }

    public function test_posted_entries_are_immutable_and_balanced_in_the_database(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $entry = app(Ledger::class)->post('OD', CarbonImmutable::today(), 'Apport', [
            EntryLine::debit('521', 100000, 'Apport'), EntryLine::credit('101', 100000, 'Apport'),
        ], $setup['user']);

        foreach ([
            fn () => DB::table('journal_entry_lines')->where('entry_id', $entry->id)->update(['debit' => 1]),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'Autre']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Posted entries are immutable.');
            } catch (QueryException) {
            }
        }

        // Une écriture déséquilibrée ne peut pas être validée, même en contournant l'application.
        try {
            DB::transaction(function () use ($entry): void {
                $id = (string) Str::uuid();
                DB::table('journal_entries')->insert(['id' => $id, 'tenant_id' => $entry->tenant_id, 'journal_id' => $entry->journal_id,
                    'period_id' => $entry->period_id, 'entry_date' => $entry->entry_date, 'description' => 'X', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('journal_entry_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $entry->tenant_id, 'entry_id' => $id,
                    'position' => 1, 'account_id' => ChartAccount::query()->where('code', '521')->value('id'), 'debit' => 10, 'credit' => 0, 'label' => 'X']);
                // La contrainte est différée au COMMIT ; le test tourne dans une transaction : on force la vérification.
                DB::statement('SET CONSTRAINTS journal_entries_balanced IMMEDIATE');
                DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'number' => 'OD-X', 'posted_at' => now()]);
            });
            $this->fail('An unbalanced entry must be rejected at commit.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('not balanced', $e->getMessage());
        }

        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_locked_periods_refuse_entries_and_automatic_entries_move_to_the_next_open_period(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $calendar = app(FiscalCalendar::class);
        $today = CarbonImmutable::today();
        $period = $calendar->periodFor($today);
        $calendar->lock($period, $setup['user']);

        try {
            app(Ledger::class)->post('OD', $today, 'Test', [EntryLine::debit('521', 10, 'x'), EntryLine::credit('101', 10, 'x')], $setup['user']);
            $this->fail('Locked period.');
        } catch (InvalidAccountingOperation $e) {
            $this->assertStringContainsString('verrouillée', $e->getMessage());
        }

        $moved = app(Ledger::class)->post('OD', $today, 'Auto', [EntryLine::debit('521', 10, 'x'), EntryLine::credit('101', 10, 'x')], null, shiftToOpenPeriod: true);
        $this->assertTrue($moved->entry_date->greaterThan($period->ends_on), 'Shifted to the first day of the next open period.');

        // Clôture : définitive et dans l'ordre.
        try {
            $calendar->close($calendar->periodFor($today->endOfYear()), $setup['user']);
            $this->fail('Earlier periods are still open.');
        } catch (InvalidAccountingOperation) {
        }
    }

    public function test_manual_entries_go_through_draft_then_post_and_corrections_by_reversal(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $ledger = app(Ledger::class);

        $draft = $ledger->saveDraft(null, 'OD', CarbonImmutable::today(), 'Frais bancaires', [
            EntryLine::debit('631', 2500, 'Frais'), EntryLine::credit('521', 2000, 'Frais'),
        ], $setup['user']);

        try {
            $ledger->postDraft($draft, $setup['user']);
            $this->fail('Unbalanced draft.');
        } catch (InvalidAccountingOperation) {
        }

        $draft = $ledger->saveDraft($draft, 'OD', CarbonImmutable::today(), 'Frais bancaires', [
            EntryLine::debit('631', 2500, 'Frais'), EntryLine::credit('521', 2500, 'Frais'),
        ], $setup['user']);
        $this->assertSame(0, $this->balanceOf('631'), 'Drafts never reach the reports.');

        $posted = $ledger->postDraft($draft, $setup['user']);
        $this->assertSame('OD-'.now()->year.'-000001', $posted->number);
        $this->assertSame(2500, $this->balanceOf('631'));

        $reversal = $ledger->reverse($posted, CarbonImmutable::today(), 'Erreur de compte', $setup['user']);
        $this->assertSame($posted->id, $reversal->reversal_of_id);
        $this->assertSame(0, $this->balanceOf('631'));

        $this->expectException(InvalidAccountingOperation::class);
        $ledger->reverse($posted->refresh(), CarbonImmutable::today(), 'Encore', $setup['user']);
    }

    public function test_trial_balance_is_balanced_and_year_end_carries_balances_forward(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $ledger = app(Ledger::class);
        $start = CarbonImmutable::today()->startOfYear();
        $ledger->post('OD', $start, 'Capital', [EntryLine::debit('521', 500000, 'Apport'), EntryLine::credit('101', 500000, 'Apport')], $setup['user']);
        $ledger->post('OD', $start->addDays(3), 'Ventes', [EntryLine::debit('521', 118000, 'V'), EntryLine::credit('701', 100000, 'V'), EntryLine::credit('4431', 18000, 'V')], $setup['user']);
        $ledger->post('OD', $start->addDays(4), 'Loyer', [EntryLine::debit('622', 30000, 'L'), EntryLine::credit('521', 30000, 'L')], $setup['user']);

        $balance = app(AccountingReports::class)->trialBalance($start, $start->endOfYear());
        $this->assertSame(array_sum(array_column($balance, 'debit')), array_sum(array_column($balance, 'credit')));

        $calendar = app(FiscalCalendar::class);
        $year = FiscalYear::query()->firstOrFail();

        foreach ($year->periods as $period) {
            $calendar->lock($period, $setup['user']);
            $calendar->close($period->refresh(), $setup['user']);
        }

        app(YearEndClosing::class)->close($year, $setup['user']);

        $nextStart = $start->addYear();
        $opening = JournalEntry::query()->where('source_type', 'FiscalYear')->firstOrFail();
        $this->assertSame($nextStart->toDateString(), $opening->entry_date->toDateString());
        $reports = app(AccountingReports::class);
        $bank = ChartAccount::query()->where('code', '521')->value('id');
        $this->assertSame(588000, $reports->balance($bank, $nextStart), 'Bank balance carried forward, not double counted.');
        $this->assertSame(-70000, $reports->balance(ChartAccount::query()->where('code', '1301')->value('id'), $nextStart), 'Profit 100 000 − 30 000.');
        $this->assertSame(0, $reports->balance(ChartAccount::query()->where('code', '701')->value('id'), $nextStart), 'Income accounts restart at zero.');
    }

    public function test_bank_reconciliation_completes_only_when_it_matches_the_statement(): void
    {
        $setup = $this->createTenantWithMember();
        $this->setUpAccounting($setup);
        $ledger = app(Ledger::class);
        $in = $ledger->post('OD', CarbonImmutable::today(), 'Dépôt', [EntryLine::debit('521', 80000, 'D'), EntryLine::credit('101', 80000, 'D')], $setup['user']);
        $ledger->post('OD', CarbonImmutable::today(), 'Frais', [EntryLine::debit('631', 1000, 'F'), EntryLine::credit('521', 1000, 'F')], $setup['user']);
        $bank = ChartAccount::query()->where('code', '521')->firstOrFail();
        $service = app(BankReconciliationService::class);

        $reconciliation = $service->start($bank, CarbonImmutable::today(), 80000, 'Relevé 1', $setup['user']);
        $service->toggle($reconciliation, [$in->lines()->where('account_id', $bank->id)->value('id')], true);
        $this->assertSame(80000, $service->reconciledBalance($reconciliation));
        $service->complete($reconciliation, $setup['user']);

        $second = $service->start($bank, CarbonImmutable::today(), 70000, 'Relevé 2', $setup['user']);
        $this->expectException(InvalidAccountingOperation::class);
        $service->complete($second, $setup['user']); // 80 000 rapprochés ≠ 70 000
    }
}
