<?php

namespace Tests\Feature\Sales;

use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentService;
use App\Models\SalesDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le journal des paiements fait foi : PostgreSQL refuse, à la validation de la transaction,
 * une facture dont le montant payé diffère de la somme de ses paiements, et un paiement en
 * espèces sans mouvement de caisse.
 *
 * Les tests tournent dans une transaction jamais validée : on déclenche les contrôles
 * différés à la main avec SET CONSTRAINTS ... IMMEDIATE, ce qui les exécute pour tout ce
 * qui a été écrit jusque-là.
 */
class PaymentAggregateGuardTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function validate(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function assertRefused(callable $tamper, string $message): void
    {
        // Point de sauvegarde : l'erreur de PostgreSQL annule la transaction en cours.
        DB::beginTransaction();

        try {
            $tamper();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('PostgreSQL should have refused this inconsistency.');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_real_payment_flows_stay_consistent(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup, 5000);

        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 4000], ['wave', 2000]]);
        $credit = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 1000]], customerId: $customer->id);
        $this->enter($setup);
        app(PaymentService::class)->receive($credit, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], (string) Str::uuid());
        app(CreditNoteService::class)->create($sale, [['line_id' => $sale->lines()->value('id'), 'quantity' => '1', 'restock' => true]], 'Retour', $setup['user'], (string) Str::uuid());
        app(PaymentService::class)->refund($sale->refresh(), 500, 'cash', $session->id, 'Geste commercial', $setup['user'], (string) Str::uuid());

        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '5', 'unit_cost' => 900]], null, null, $setup['user'], (string) Str::uuid());
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'F-1', 'goods_receipt_id' => $receipt->id, 'lines' => $invoices->linesFromReceipt($receipt)], $setup['user']), $setup['user']);
        app(SupplierPaymentService::class)->pay($invoice, 'cash', 1000, $session->id, null, $setup['user'], (string) Str::uuid());

        $this->validate();
        $this->assertSame(5500, $sale->refresh()->amount_paid);
        $this->assertSame(1, DB::table('cash_movements')->where('type', 'supplier_payment')->count());
    }

    public function test_an_invoice_cannot_be_marked_paid_without_payments(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$rice->id, '1']], [], customerId: $customer->id);

        $this->assertRefused(
            fn () => DB::table('sales_documents')->where('id', $invoice->id)->update(['amount_paid' => $invoice->total]),
            'differs from its payments',
        );
    }

    public function test_a_payment_without_the_invoice_total_being_updated_is_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$rice->id, '1']], [], customerId: $customer->id);

        $this->assertRefused(function () use ($setup, $invoice) {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $setup['tenant']->id, 'number' => 'RC-X', 'direction' => 'in',
                'kind' => 'customer_payment', 'method' => 'transfer', 'amount' => 500, 'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $invoice->customer_id, 'sales_document_id' => $invoice->id, 'idempotency_key' => (string) Str::uuid().':0',
                'created_by' => $setup['user']->id, 'paid_at' => now(),
            ]);
        }, 'differs from its payments');
    }

    public function test_a_cash_payment_needs_exactly_one_cash_movement(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 400]], customerId: $customer->id);
        $payment = DB::table('payments')->where('sales_document_id', $invoice->id)->first();
        $movement = DB::table('cash_movements')->where('payment_id', $payment->id)->first();

        // Un second mouvement pour le même paiement est refusé tout de suite (index unique).
        try {
            DB::transaction(fn () => DB::table('cash_movements')->insert([
                ...(array) $movement, 'id' => (string) Str::uuid(), 'number' => null, 'idempotency_key' => (string) Str::uuid(),
            ]));
            $this->fail('A payment must not get a second cash movement.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cash_movements_payment_unique', $e->getMessage());
        }

        // Un paiement en espèces sans mouvement est refusé à la validation.
        $this->assertRefused(function () use ($setup, $invoice, $session) {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $setup['tenant']->id, 'number' => 'RC-Y', 'direction' => 'in',
                'kind' => 'customer_payment', 'method' => 'cash', 'amount' => 100, 'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $invoice->customer_id, 'sales_document_id' => $invoice->id, 'cash_session_id' => $session->id,
                'idempotency_key' => (string) Str::uuid().':0', 'created_by' => $setup['user']->id, 'paid_at' => now(),
            ]);
            DB::table('sales_documents')->where('id', $invoice->id)->update(['amount_paid' => $invoice->amount_paid + 100]);
        }, 'has no cash movement');
    }

    public function test_a_supplier_invoice_total_must_match_its_payments(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $this->enter($setup);
        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '5', 'unit_cost' => 900]], null, null, $setup['user'], (string) Str::uuid());
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'F-2', 'goods_receipt_id' => $receipt->id, 'lines' => $invoices->linesFromReceipt($receipt)], $setup['user']), $setup['user']);

        $this->assertRefused(
            fn () => DB::table('supplier_invoices')->where('id', $invoice->id)->update(['amount_paid' => 1]),
            'differs from its payments',
        );
    }

    public function test_a_restore_is_exempt(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $invoice = $this->sell($setup, $this->openSession($setup, 0), [[$rice->id, '1']], [], customerId: $customer->id);

        DB::statement("SELECT set_config('diaba.restore', 'on', true)");
        DB::table('sales_documents')->where('id', $invoice->id)->update(['amount_paid' => 1]);
        $this->validate();

        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->findOrFail($invoice->id)->amount_paid);
    }
}
