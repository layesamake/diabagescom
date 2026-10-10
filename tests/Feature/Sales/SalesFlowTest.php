<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Cash\InvalidCashOperation;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\Quantity;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\InvalidSalesOperation;
use App\Domain\Sales\PaymentService;
use App\Domain\Sales\Pricing;
use App\Domain\Sales\SalesDocumentService;
use App\Models\AuditEvent;
use App\Models\CashMovement;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesFlowTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_tax_is_extracted_from_inclusive_prices_without_floats(): void
    {
        $line = Pricing::saleLine(Quantity::of('3'), 1180, 40, 1800);

        $this->assertSame(['gross' => 3540, 'discount' => 40, 'total' => 3500, 'excl_tax' => 2966, 'tax' => 534], $line);
        $this->assertSame(1000, Pricing::exclusiveFromInclusive(1180, 1800));
        $this->assertSame(180, Pricing::taxOnExclusive(1000, 1800));
        $this->assertSame(['excl_tax' => 37500, 'tax' => 6750, 'total' => 44250], Pricing::purchaseLine(Quantity::of('2.5'), 15000, 1800));
    }

    public function test_a_counter_sale_produces_invoice_payment_stock_movement_and_audit_once(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000); // prix 2 000 TTC
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $key = (string) Str::uuid();

        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]], key: $key);

        $this->assertSame(SalesDocument::VALIDATED, $sale->status);
        $this->assertSame('FA-'.now()->year.'-00001', $sale->number);
        $this->assertSame(6000, $sale->total);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('7.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $this->assertSame(1000, $sale->lines()->first()?->unit_cost, 'The average cost is frozen on the line.');
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('RC-'.now()->year.'-00001', Payment::query()->value('number'));
        $this->assertSame(16000, app(CashService::class)->drawerBalance($session));
        $this->assertTrue(AuditEvent::query()->where('action', 'sales.invoice_validated')->exists());

        // Double clic / renvoi après coupure : même vente, rien de doublé.
        $replay = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]], key: $key);
        $this->assertSame($sale->id, $replay->id);
        $this->assertSame(1, SalesDocument::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'sale')->count());
        $this->assertSame('7.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_multi_mode_and_partial_payments_on_credit(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $customer = $this->makeCustomer($setup, creditLimit: 5000, terms: 15);

        $sale = $this->sell($setup, $session, [[$rice->id, '5']], [['cash', 3000], ['wave', 4000]], customerId: $customer->id);

        $this->assertSame(10000, $sale->total);
        $this->assertSame(7000, $sale->amount_paid);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame(now()->addDays(15)->toDateString(), $sale->due_date?->toDateString());
        $this->assertSame(['cash' => 3000, 'wave' => 4000], app(CashService::class)->summary($session)['by_method']);
        $this->assertSame(13000, app(CashService::class)->drawerBalance($session), 'Only cash enters the drawer.');

        // Règlement ultérieur du solde.
        app(PaymentService::class)->receive($sale, [['method' => 'orange_money', 'amount' => 3000, 'reference' => 'OM123']], null, $setup['user'], (string) Str::uuid());
        $this->assertSame('paid', $sale->refresh()->payment_status);

        // Jamais au-delà du reste à payer.
        $this->expectException(InvalidSalesOperation::class);
        app(PaymentService::class)->receive($sale, [['method' => 'wave', 'amount' => 1]], null, $setup['user'], (string) Str::uuid());
    }

    public function test_credit_requires_a_customer_with_a_sufficient_limit(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup);
        $noLimit = $this->makeCustomer($setup);
        $limited = $this->makeCustomer($setup, creditLimit: 5000);

        foreach ([null, $noLimit->id] as $customerId) {
            try {
                $this->sell($setup, $session, [[$rice->id, '1']], [], customerId: $customerId);
                $this->fail('Credit sale should be refused.');
            } catch (InvalidSalesOperation) {
            }
        }

        $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 1000]], customerId: $limited->id); // 3 000 dus

        try {
            $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 1000]], customerId: $limited->id); // +3 000 > 5 000
            $this->fail('Credit limit exceeded.');
        } catch (InvalidSalesOperation $e) {
            $this->assertStringContainsString('Plafond de crédit dépassé', $e->getMessage());
        }

        // Rien n'a été écrit pour les ventes refusées : ni document, ni stock.
        $this->assertSame(1, SalesDocument::query()->where('status', 'validated')->count());
        $this->assertSame('18.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_stock_cannot_go_negative_without_explicit_permission_and_reason(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '1', 1000]]);
        $cashier = $this->member($setup, 'cashier');
        $session = $this->openSession($cashier);

        try {
            $this->sell($cashier, $session, [[$rice->id, '2']], [['cash', 4000]]);
            $this->fail('Insufficient stock should block the sale.');
        } catch (InsufficientStock) {
        }

        try {
            $this->sell($cashier, $session, [[$rice->id, '2']], [['cash', 4000]], negativeReason: 'Livraison en cours');
            $this->fail('Cashier has no sell_negative_stock permission.');
        } catch (InvalidSalesOperation) {
        }

        $sale = $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 4000]], negativeReason: 'Livraison en cours');
        $this->assertSame('-1.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $this->assertSame('Livraison en cours', $sale->negative_stock_reason);
        $this->assertSame(0, SalesDocument::query()->where('status', 'draft')->count(), 'Failed checkouts leave no draft behind.');
    }

    public function test_discounts_and_price_changes_are_bounded_by_permissions(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup); // 2 000 TTC
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $cashier = $this->member($setup, 'cashier');
        $session = $this->openSession($cashier);

        $ok = $this->sell($cashier, $session, [[$rice->id, '1', null, 200]], [['cash', 1800]]); // 10 % : remise standard
        $this->assertSame(200, $ok->total_discount);

        foreach ([[$rice->id, '1', null, 300], [$rice->id, '1', 1500]] as $line) {
            try {
                $this->sell($cashier, $session, [$line], [['cash', 1500]]);
                $this->fail('Exceptional discount or price override must be refused for a cashier.');
            } catch (InvalidSalesOperation) {
            }
        }

        $admin = $this->sell($setup, $session, [[$rice->id, '1', 1500]], [['cash', 1500]]);
        $this->assertSame(1500, $admin->total);
    }

    public function test_a_validated_invoice_is_frozen_in_the_database(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);

        foreach ([
            fn () => DB::table('sales_documents')->where('id', $sale->id)->update(['total' => 1, 'total_gross' => 1, 'total_excl_tax' => 1, 'total_tax' => 0]),
            fn () => DB::table('sales_document_lines')->where('document_id', $sale->id)->update(['label' => 'Autre']),
            fn () => DB::table('sales_document_lines')->where('document_id', $sale->id)->delete(),
            fn () => DB::table('sales_documents')->where('id', $sale->id)->delete(),
            fn () => DB::table('payments')->update(['amount' => 1]),
            fn () => DB::table('cash_movements')->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Mutation of a validated document or payment must fail.');
            } catch (QueryException) {
            }
        }

        $this->expectException(InvalidSalesOperation::class);
        app(SalesDocumentService::class)->cancel($sale, 'Erreur', $setup['user']);
    }

    public function test_a_credit_note_returns_goods_reduces_the_invoice_and_allows_a_refund(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $oil = $this->makeProduct($setup, 'HUILE', 'U', 2500);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000], [$setup['warehouse']->id, $oil->id, '10', 2500]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '3', null, 300], [$oil->id, '1']], [['cash', 10700]]);
        $riceLine = $sale->lines()->where('product_id', $rice->id)->firstOrFail();
        $key = (string) Str::uuid();

        $credit = app(CreditNoteService::class)->create($sale, [['line_id' => $riceLine->id, 'quantity' => '1', 'restock' => true]], 'Sac abîmé', $setup['user'], $key);

        $this->assertSame('AV-'.now()->year.'-00001', $credit->number);
        $this->assertSame($sale->id, $credit->source_document_id);
        $this->assertSame(1900, $credit->total, '5 700 × 1/3 = 1 900 (remise proratisée).');
        $this->assertSame('8.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $sale->refresh();
        $this->assertSame(1900, $sale->amount_credited);
        $this->assertSame('refund_due', $sale->payment_status);

        // Rejeu : pas de second avoir ni de second retour en stock.
        app(CreditNoteService::class)->create($sale, [['line_id' => $riceLine->id, 'quantity' => '1', 'restock' => true]], 'Sac abîmé', $setup['user'], $key);
        $this->assertSame(1, SalesDocument::query()->where('type', 'credit_note')->count());
        $this->assertSame('8.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);

        // Le reste de la ligne solde exactement le montant facturé.
        app(CreditNoteService::class)->create($sale, [['line_id' => $riceLine->id, 'quantity' => '2', 'restock' => false]], 'Geste commercial', $setup['user'], (string) Str::uuid());
        $this->assertSame(5700, $sale->refresh()->amount_credited);

        try {
            app(CreditNoteService::class)->create($sale, [['line_id' => $riceLine->id, 'quantity' => '1', 'restock' => false]], 'Encore', $setup['user'], (string) Str::uuid());
            $this->fail('Cannot credit more than invoiced.');
        } catch (InvalidSalesOperation) {
        }

        $refund = app(PaymentService::class)->refund($sale, 5700, 'cash', $session->id, 'Remboursement avoir', $setup['user'], (string) Str::uuid());
        $this->assertSame('out', $refund->direction);
        $this->assertSame('paid', $sale->refresh()->payment_status);
        $this->assertSame(10000 + 10700 - 5700, app(CashService::class)->drawerBalance($session));
    }

    public function test_quote_to_order_to_invoice_keeps_links_and_quoted_prices(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->enter($setup);
        $documents = app(SalesDocumentService::class);

        $quote = $documents->saveDraft(null, 'quote', ['warehouse_id' => $setup['warehouse']->id, 'customer_id' => $customer->id,
            'lines' => [['product_id' => $rice->id, 'quantity' => '4']]], $setup['user']);
        $quote = $documents->validate($quote, $setup['user']);
        $this->assertSame('DV-'.now()->year.'-00001', $quote->number);
        $this->assertNotNull($quote->valid_until);
        $this->assertSame('10.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity'], 'A quote moves no stock.');

        // Le prix catalogue change : le devis accepté reste au prix proposé.
        DB::table('products')->where('id', $rice->id)->update(['sale_price' => 9999]);

        $order = $documents->validate($documents->convert($quote, 'order', $setup['user']), $setup['user']);
        $this->assertSame(SalesDocument::CONVERTED, $quote->refresh()->status);
        $this->assertSame($documents->convert($quote, 'order', $setup['user'])->id, $order->id, 'Replayed conversion returns the same document.');

        $invoice = $documents->validate($documents->convert($order, 'invoice', $setup['user']), $setup['user']);
        $this->assertSame($order->id, $invoice->source_document_id);
        $this->assertSame(8000, $invoice->total);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('6.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $this->assertSame(['code', 'name', 'phone', 'email', 'address_line', 'city', 'tax_number'], array_keys($invoice->customer_snapshot ?? []));
    }

    public function test_a_closed_register_takes_no_payment_and_closing_explains_differences(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup, 5000);
        $cash = app(CashService::class);

        try {
            $cash->recordOther($session->id, 'expense', 6000, 'Carburant', 'Livraison', $setup['user'], (string) Str::uuid());
            $this->fail('Cannot spend more cash than the drawer holds.');
        } catch (InvalidCashOperation) {
        }

        $expense = $cash->recordOther($session->id, 'expense', 1500, 'Carburant', 'Livraison client', $setup['user'], (string) Str::uuid());
        $this->assertSame('DP-'.now()->year.'-00001', $expense->number);
        $this->assertSame(3500, $cash->drawerBalance($session));

        try {
            $cash->close($session, 3000, null, $setup['user']);
            $this->fail('A difference needs an explanation.');
        } catch (InvalidCashOperation) {
        }

        $closed = $cash->close($session, 3000, 'Erreur de rendu de monnaie', $setup['user']);
        $this->assertSame(['expected' => 3500, 'counted' => 3000, 'difference' => -500], ['expected' => $closed->expected_amount, 'counted' => $closed->counted_amount, 'difference' => $closed->difference]);

        $this->expectException(InvalidCashOperation::class);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
    }

    public function test_one_open_session_per_register_and_cash_movements_are_append_only(): void
    {
        $setup = $this->createTenantWithMember();
        $session = $this->openSession($setup);

        try {
            $this->openSession($setup);
            $this->fail('A register has one open session at a time.');
        } catch (InvalidCashOperation) {
        }

        $movement = app(CashService::class)->recordOther($session->id, 'income', 500, 'Vente carton', 'Recette diverse', $setup['user'], (string) Str::uuid());
        $this->expectException(\LogicException::class);
        $movement->update(['amount' => 1]);
        $this->assertSame(1, CashMovement::query()->count());
    }
}
