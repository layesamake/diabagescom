<?php

namespace Tests\Feature\Backup;

use App\Domain\Accounting\AccountingPoster;
use App\Domain\Accounting\AccountingSetup;
use App\Domain\Cash\CashService;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Domain\Purchases\SupplierReturnService;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Les contrôles métier de l'exercice de restauration (docker/backup/integrity-checks.sql)
 * ne lèvent aucune fausse alerte sur des opérations réelles, et détectent une corruption.
 */
class IntegrityChecksTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * Exécute les contrôles (sans les commandes propres à psql) et renvoie les anomalies par contrôle.
     *
     * @return array<string, int>
     */
    private function runChecks(): array
    {
        $sql = collect(explode("\n", (string) file_get_contents(base_path('docker/backup/integrity-checks.sql'))))
            ->reject(fn (string $line) => str_starts_with(ltrim($line), '\\'))
            ->implode("\n");

        DB::unprepared('DROP TABLE IF EXISTS pg_temp.drill_checks; DROP FUNCTION IF EXISTS pg_temp.check(text, text[], text);');
        DB::unprepared($sql);

        return DB::table('drill_checks')->where('skipped', false)->pluck('anomalies', 'name')->map(fn ($n) => (int) $n)->all();
    }

    public function test_real_business_flows_pass_every_check_and_a_corruption_is_detected(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear(), CarbonImmutable::today()->subMonth());

        // Ventes : comptoir multi-mode, crédit, avoir avec retour en stock, remboursement.
        $session = $this->openSession($setup, 5000);
        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 4000], ['wave', 2000]]);
        $credit = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 1000]], customerId: $customer->id);
        $this->enter($setup);
        app(PaymentService::class)->receive($credit, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], (string) Str::uuid());
        app(CreditNoteService::class)->create($sale, [['line_id' => $sale->lines()->value('id'), 'quantity' => '1', 'restock' => true]], 'Retour', $setup['user'], (string) Str::uuid());
        app(PaymentService::class)->refund($sale->refresh(), 2000, 'cash', $session->id, 'Remboursement', $setup['user'], (string) Str::uuid());

        // Achats : réception, facture, règlement en espèces, retour.
        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '5', 'unit_cost' => 900]], null, null, $setup['user'], (string) Str::uuid());
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'F-9', 'goods_receipt_id' => $receipt->id, 'lines' => $invoices->linesFromReceipt($receipt)], $setup['user']), $setup['user']);
        app(SupplierPaymentService::class)->pay($invoice, 'cash', 1000, $session->id, null, $setup['user'], (string) Str::uuid());
        app(SupplierReturnService::class)->create($receipt, [$receipt->lines()->value('id') => '1'], 'Avarie', $setup['user'], (string) Str::uuid());

        // Caisse : dépense puis fermeture avec écart ; comptabilisation de l'ensemble.
        $cash = app(CashService::class);
        $cash->recordOther($session->id, 'expense', 500, 'Carburant', 'Livraison', $setup['user'], (string) Str::uuid());
        $cash->close($session, $cash->drawerBalance($session) - 100, 'Erreur de rendu', $setup['user']);
        $this->assertGreaterThan(5, app(AccountingPoster::class)->postPending()['posted']);

        $results = $this->runChecks();
        $this->assertCount(13, $results, 'Every module is present and checked.');
        $this->assertSame(array_fill_keys(array_keys($results), 0), $results);

        // Corruption simulée (contourne les protections, comme une restauration abîmée).
        DB::statement('SET session_replication_role = replica');
        DB::table('inventory_balances')->where('product_id', $rice->id)->update(['quantity_on_hand' => '999']);
        DB::table('sales_documents')->where('id', $credit->id)->update(['amount_paid' => 1]);
        DB::statement('SET session_replication_role = DEFAULT');

        try {
            $this->runChecks();
            $this->fail('A corrupted restore must fail the drill.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('sauvegarde NON validée', $e->getMessage());
        }
    }
}
