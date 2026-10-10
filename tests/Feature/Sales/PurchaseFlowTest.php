<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\InvalidPurchaseOperation;
use App\Domain\Purchases\PurchaseOrderService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Domain\Purchases\SupplierReturnService;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_order_receipt_invoice_payment_and_return(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $supplier = $this->makeSupplier($setup);
        $warehouse = $setup['warehouse'];
        $this->enter($setup);

        // Commande : brouillon → confirmée, montants HT + TVA figés.
        $orders = app(PurchaseOrderService::class);
        $order = $orders->saveDraft(null, ['warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $rice->id, 'quantity' => '10', 'unit_cost' => 1200]]], $setup['user']);
        $order = $orders->confirm($order, $setup['user']);
        $this->assertSame('CF-'.now()->year.'-00001', $order->number);
        $this->assertSame(['excl' => 12000, 'tax' => 2160, 'total' => 14160], ['excl' => $order->total_excl_tax, 'tax' => $order->total_tax, 'total' => $order->total]);

        $line = $order->lines()->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('purchase_order_lines')->where('id', $line->id)->update(['unit_cost' => 1]));
            $this->fail('A confirmed order is frozen.');
        } catch (QueryException) {
        }

        // Réception partielle : le stock entre au coût d'achat HT ; rejouer ne double rien.
        $receipts = app(GoodsReceiptService::class);
        $key = (string) Str::uuid();
        $receipt = $receipts->receiveOrder($order, [$line->id => '6'], 'BL-77', null, $setup['user'], $key);
        $receipts->receiveOrder($order, [$line->id => '6'], 'BL-77', null, $setup['user'], $key);

        $this->assertSame('BR-'.now()->year.'-00001', $receipt->number);
        $this->assertSame(['quantity' => '6.000', 'average_cost' => 1200], $this->balance($warehouse->id, $rice->id));
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(PurchaseOrder::PARTIALLY_RECEIVED, $order->refresh()->status);

        try {
            $receipts->receiveOrder($order, [$line->id => '5'], null, null, $setup['user'], (string) Str::uuid());
            $this->fail('Cannot receive more than ordered.');
        } catch (InvalidPurchaseOperation) {
        }

        // Facture fournisseur depuis la réception : la dette naît à la validation.
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'supplier_reference' => 'FAC-2026-15', 'goods_receipt_id' => $receipt->id,
            'lines' => $invoices->linesFromReceipt($receipt)], $setup['user']);
        $invoice = $invoices->validate($invoice, $setup['user']);
        $this->assertSame($order->id, $invoice->purchase_order_id);
        $this->assertSame(8496, $invoice->total); // 6 × 1 200 = 7 200 HT + 1 296 TVA
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_date?->toDateString());

        $duplicate = $invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'supplier_reference' => 'fac-2026-15', 'lines' => [['label' => 'Riz', 'quantity' => '1', 'unit_cost' => 10, 'tax_rate_basis_points' => 1800]]], $setup['user']);

        try {
            $invoices->validate($duplicate, $setup['user']);
            $this->fail('Same supplier reference twice.');
        } catch (InvalidPurchaseOperation $e) {
            $this->assertStringContainsString($invoice->number ?? '', $e->getMessage());
        }

        // Règlement partiel en espèces depuis la caisse.
        $session = $this->openSession($setup, 20000);
        $payment = app(SupplierPaymentService::class)->pay($invoice, 'cash', 5000, $session->id, null, $setup['user'], (string) Str::uuid());
        $this->assertSame('PF-'.now()->year.'-00001', $payment->number);
        $this->assertSame(15000, app(CashService::class)->drawerBalance($session));
        $this->assertSame('partial', $invoice->refresh()->payment_status);

        try {
            app(SupplierPaymentService::class)->pay($invoice, 'transfer', 3497, null, 'VIR-1', $setup['user'], (string) Str::uuid());
            $this->fail('Cannot pay more than due.');
        } catch (InvalidPurchaseOperation) {
        }

        // Retour fournisseur : sortie de stock et avoir imputé sur la facture.
        $return = app(SupplierReturnService::class)->create($receipt, [$receipt->lines()->value('id') => '2'], 'Sacs percés', $setup['user'], (string) Str::uuid());
        $this->assertSame('RF-'.now()->year.'-00001', $return->number);
        $this->assertSame(2832, $return->total);
        $this->assertSame('4.000', $this->balance($warehouse->id, $rice->id)['quantity']);
        $invoice->refresh();
        $this->assertSame(2832, $invoice->amount_credited);
        $this->assertSame(8496 - 5000 - 2832, $invoice->balanceDue());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'supplier_return')->count());

        try {
            app(SupplierReturnService::class)->create($receipt, [$receipt->lines()->value('id') => '5'], 'Trop', $setup['user'], (string) Str::uuid());
            $this->fail('Cannot return more than received.');
        } catch (InvalidPurchaseOperation) {
        }

        // Reliquat abandonné : commande soldée.
        $this->assertSame(PurchaseOrder::CLOSED, $orders->cancel($order, 'Rupture fournisseur', $setup['user'])->status);
    }

    public function test_a_return_never_makes_stock_negative(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $supplier = $this->makeSupplier($setup);
        $this->enter($setup);
        $receipt = app(GoodsReceiptService::class)->receiveDirect($setup['warehouse']->id, $supplier->id,
            [['product_id' => $rice->id, 'quantity' => '3', 'unit_cost' => 900]], null, null, $setup['user'], (string) Str::uuid());
        $this->move($setup, 'adjustment', [[$setup['warehouse']->id, $rice->id, '-2']], reason: 'Casse');

        $this->expectException(InsufficientStock::class);
        app(SupplierReturnService::class)->create($receipt, [$receipt->lines()->value('id') => '3'], 'Erreur de livraison', $setup['user'], (string) Str::uuid());
    }

    public function test_a_validated_supplier_invoice_cannot_be_cancelled_or_edited(): void
    {
        $setup = $this->createTenantWithMember();
        $supplier = $this->makeSupplier($setup);
        $this->enter($setup);
        $invoices = app(SupplierInvoiceService::class);
        $invoice = $invoices->validate($invoices->saveDraft(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $setup['warehouse']->id,
            'supplier_reference' => 'X1', 'lines' => [['label' => 'Transport', 'quantity' => '1', 'unit_cost' => 10000, 'tax_rate_basis_points' => 0]]], $setup['user']), $setup['user']);

        try {
            DB::transaction(fn () => DB::table('supplier_invoices')->where('id', $invoice->id)->update(['total' => 1, 'total_excl_tax' => 1]));
            $this->fail('Frozen.');
        } catch (QueryException) {
        }

        $this->expectException(InvalidPurchaseOperation::class);
        $invoices->cancel($invoice, $setup['user']);
        $this->assertSame(SupplierInvoice::VALIDATED, $invoice->refresh()->status);
    }
}
