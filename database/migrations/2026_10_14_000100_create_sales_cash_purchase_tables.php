<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-05 — ventes, caisse et achats.
 *
 * Invariants garantis en base (en plus des services) :
 * - un document validé est figé : ses montants, son numéro, son client et ses
 *   lignes ne changent plus (triggers) ; seuls le suivi des règlements et la
 *   conversion d'un devis/commande évoluent ;
 * - paiements, mouvements de caisse, réceptions et retours fournisseurs sont
 *   append-only ; une erreur se corrige par un avoir, un remboursement ou un retour ;
 * - montants XOF en bigint, quantités numeric(15,3), jamais de flottant ;
 * - une seule session ouverte par caisse ; clés d'idempotence uniques par entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCashTables();
        $this->createSalesTables();
        $this->createPurchaseTables();
        $this->createPaymentTables();
        $this->createTriggers();

        // Dépôts existants : une caisse par défaut (les nouveaux dépôts l'ont à la création).
        DB::statement("INSERT INTO cash_registers (id, tenant_id, warehouse_id, name, active, created_at, updated_at)
            SELECT uuidv7(), tenant_id, id, 'Caisse principale', true, now(), now() FROM warehouses");
    }

    private function createCashTables(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['warehouse_id', 'name']);
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('register_id');
            $table->string('status', 16)->default('open');
            $table->bigInteger('opening_amount');
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('counted_amount')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->string('closing_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'register_id'])->references(['tenant_id', 'id'])->on('cash_registers')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'opened_at']);
        });
        DB::statement("ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_status CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_amounts CHECK (opening_amount >= 0 AND (counted_amount IS NULL OR counted_amount >= 0))');
        DB::statement("ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_closed CHECK (status = 'open' OR (closed_at IS NOT NULL AND expected_amount IS NOT NULL AND counted_amount IS NOT NULL AND difference = counted_amount - expected_amount))");
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open ON cash_sessions (register_id) WHERE status = 'open'");
    }

    private function createSalesTables(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type', 16);
            $table->string('number', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('channel', 16)->default('backoffice');
            $table->uuid('warehouse_id');
            $table->uuid('customer_id')->nullable();
            $table->jsonb('customer_snapshot')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->bigInteger('total_gross')->default(0);
            $table->bigInteger('total_discount')->default(0);
            $table->bigInteger('total_excl_tax')->default(0);
            $table->bigInteger('total_tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_credited')->default(0);
            $table->string('payment_status', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('negative_stock_reason', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'type', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'source_document_id'])->references(['tenant_id', 'id'])->on('sales_documents')->restrictOnDelete();
            $table->index(['tenant_id', 'type', 'status', 'created_at']);
            $table->index(['tenant_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_type CHECK (type IN ('quote', 'order', 'invoice', 'credit_note'))");
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_status CHECK (status IN ('draft', 'validated', 'converted', 'cancelled'))");
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_channel CHECK (channel IN ('pos', 'backoffice'))");
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_payment_status CHECK (payment_status IS NULL OR payment_status IN ('unpaid', 'partial', 'paid', 'refund_due'))");
        DB::statement('ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_totals CHECK (
            total_gross >= 0 AND total_discount >= 0 AND total_excl_tax >= 0 AND total_tax >= 0
            AND total = total_gross - total_discount AND total = total_excl_tax + total_tax
            AND amount_paid >= 0 AND amount_credited >= 0 AND amount_credited <= total)');
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_numbered CHECK (status IN ('draft', 'cancelled') OR (number IS NOT NULL AND validated_at IS NOT NULL))");
        DB::statement("ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_credit_source CHECK (type <> 'credit_note' OR source_document_id IS NOT NULL)");

        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('document_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('product_id');
            $table->string('sku', 64);
            $table->string('label', 200);
            $table->string('unit_code', 16);
            $table->string('product_type', 16);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price');      // TTC, XOF
            $table->bigInteger('discount_amount')->default(0);
            $table->integer('tax_rate_basis_points');
            $table->bigInteger('line_total');      // TTC après remise
            $table->bigInteger('line_excl_tax');
            $table->bigInteger('line_tax');
            $table->bigInteger('unit_cost')->nullable(); // coût moyen figé à la sortie de stock
            $table->uuid('source_line_id')->nullable();
            $table->boolean('restock')->default(false);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('sales_documents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'source_line_id'])->references(['tenant_id', 'id'])->on('sales_document_lines')->restrictOnDelete();
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE sales_document_lines ADD CONSTRAINT sales_document_lines_amounts CHECK (
            quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_rate_basis_points BETWEEN 0 AND 10000
            AND line_total = round(quantity * unit_price) - discount_amount AND line_total >= 0
            AND line_excl_tax >= 0 AND line_tax >= 0 AND line_excl_tax + line_tax = line_total
            AND (unit_cost IS NULL OR unit_cost >= 0))');
    }

    private function createPurchaseTables(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id');
            $table->date('expected_on')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('total_excl_tax')->default(0);
            $table->bigInteger('total_tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status CHECK (status IN ('draft', 'confirmed', 'partially_received', 'received', 'closed', 'cancelled'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_totals CHECK (total_excl_tax >= 0 AND total_tax >= 0 AND total = total_excl_tax + total_tax)');

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('product_id');
            $table->string('sku', 64);
            $table->string('label', 200);
            $table->string('unit_code', 16);
            $table->decimal('quantity', 15, 3);
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->bigInteger('unit_cost');       // HT, XOF
            $table->integer('tax_rate_basis_points');
            $table->bigInteger('line_excl_tax');
            $table->bigInteger('line_tax');
            $table->bigInteger('line_total');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_amounts CHECK (
            quantity > 0 AND quantity_received >= 0 AND unit_cost >= 0 AND tax_rate_basis_points BETWEEN 0 AND 10000
            AND line_excl_tax = round(quantity * unit_cost) AND line_tax >= 0 AND line_total = line_excl_tax + line_tax)');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32);
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id');
            $table->uuid('purchase_order_id')->nullable();
            $table->string('supplier_reference', 64)->nullable(); // n° de bon de livraison
            $table->string('note', 500)->nullable();
            $table->bigInteger('total_excl_tax');
            $table->string('idempotency_key', 100);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'purchase_order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('receipt_id');
            $table->uuid('order_line_id')->nullable();
            $table->uuid('product_id');
            $table->string('sku', 64);
            $table->string('label', 200);
            $table->string('unit_code', 16);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_cost');
            $table->integer('tax_rate_basis_points');
            $table->bigInteger('line_excl_tax');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'receipt_id'])->references(['tenant_id', 'id'])->on('goods_receipts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_line_id'])->references(['tenant_id', 'id'])->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('receipt_id');
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_amounts CHECK (quantity > 0 AND unit_cost >= 0 AND line_excl_tax = round(quantity * unit_cost))');

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->nullable();
            $table->string('supplier_reference', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->uuid('supplier_id');
            $table->uuid('warehouse_id');
            $table->uuid('purchase_order_id')->nullable();
            $table->uuid('goods_receipt_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('total_excl_tax')->default(0);
            $table->bigInteger('total_tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_credited')->default(0);
            $table->string('payment_status', 16)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'purchase_order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'goods_receipt_id'])->references(['tenant_id', 'id'])->on('goods_receipts')->restrictOnDelete();
            $table->index(['tenant_id', 'supplier_id']);
        });
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status CHECK (status IN ('draft', 'validated', 'cancelled'))");
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_payment_status CHECK (payment_status IS NULL OR payment_status IN ('unpaid', 'partial', 'paid', 'refund_due'))");
        DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_totals CHECK (
            total_excl_tax >= 0 AND total_tax >= 0 AND total = total_excl_tax + total_tax
            AND amount_paid >= 0 AND amount_credited >= 0 AND amount_credited <= total)');
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_numbered CHECK (status <> 'validated' OR (number IS NOT NULL AND validated_at IS NOT NULL))");

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('product_id')->nullable();
            $table->string('label', 200);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_cost');
            $table->integer('tax_rate_basis_points');
            $table->bigInteger('line_excl_tax');
            $table->bigInteger('line_tax');
            $table->bigInteger('line_total');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->index('invoice_id');
        });
        DB::statement('ALTER TABLE supplier_invoice_lines ADD CONSTRAINT supplier_invoice_lines_amounts CHECK (
            quantity > 0 AND unit_cost >= 0 AND tax_rate_basis_points BETWEEN 0 AND 10000
            AND line_excl_tax = round(quantity * unit_cost) AND line_tax >= 0 AND line_total = line_excl_tax + line_tax)');

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32);
            $table->uuid('supplier_id');
            $table->uuid('warehouse_id');
            $table->uuid('goods_receipt_id');
            $table->uuid('supplier_invoice_id')->nullable();
            $table->string('reason', 500);
            $table->bigInteger('total_excl_tax');
            $table->bigInteger('total_tax');
            $table->bigInteger('total');
            $table->string('idempotency_key', 100);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'goods_receipt_id'])->references(['tenant_id', 'id'])->on('goods_receipts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supplier_invoice_id'])->references(['tenant_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE supplier_returns ADD CONSTRAINT supplier_returns_totals CHECK (total_excl_tax >= 0 AND total_tax >= 0 AND total = total_excl_tax + total_tax)');

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('return_id');
            $table->uuid('receipt_line_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_cost');
            $table->integer('tax_rate_basis_points');
            $table->bigInteger('line_excl_tax');
            $table->bigInteger('line_tax');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'return_id'])->references(['tenant_id', 'id'])->on('supplier_returns')->restrictOnDelete();
            $table->foreign(['tenant_id', 'receipt_line_id'])->references(['tenant_id', 'id'])->on('goods_receipt_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->index('return_id');
            $table->index('receipt_line_id');
        });
        DB::statement('ALTER TABLE supplier_return_lines ADD CONSTRAINT supplier_return_lines_amounts CHECK (quantity > 0 AND unit_cost >= 0 AND line_excl_tax = round(quantity * unit_cost) AND line_tax >= 0)');
    }

    private function createPaymentTables(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32);
            $table->string('direction', 3);          // in = encaissement, out = décaissement
            $table->string('kind', 24);
            $table->string('method', 16);
            $table->bigInteger('amount');
            $table->uuid('warehouse_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('sales_document_id')->nullable();
            $table->uuid('supplier_invoice_id')->nullable();
            $table->uuid('cash_session_id')->nullable();
            $table->string('reference', 100)->nullable(); // référence Wave / Orange Money / virement
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('paid_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sales_document_id'])->references(['tenant_id', 'id'])->on('sales_documents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supplier_invoice_id'])->references(['tenant_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_session_id'])->references(['tenant_id', 'id'])->on('cash_sessions')->restrictOnDelete();
            $table->index(['tenant_id', 'paid_at']);
            $table->index('sales_document_id');
            $table->index('supplier_invoice_id');
            $table->index('cash_session_id');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_direction CHECK (direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind CHECK (kind IN ('customer_payment', 'customer_refund', 'supplier_payment', 'supplier_refund'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method CHECK (method IN ('cash', 'wave', 'orange_money', 'card', 'transfer', 'cheque'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_cash_session CHECK (method <> 'cash' OR cash_session_id IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_target CHECK (
            (kind IN ('customer_payment', 'customer_refund') AND sales_document_id IS NOT NULL AND supplier_invoice_id IS NULL)
            OR (kind IN ('supplier_payment', 'supplier_refund') AND supplier_invoice_id IS NOT NULL AND sales_document_id IS NULL))");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('session_id');
            $table->string('type', 24);
            $table->bigInteger('amount');            // signé : + entrée dans le tiroir, − sortie
            $table->uuid('payment_id')->nullable();
            $table->string('label', 200)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('number', 32)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'session_id'])->references(['tenant_id', 'id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'number']);
            $table->index('session_id');
        });
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type CHECK (type IN ('customer_payment', 'customer_refund', 'supplier_payment', 'supplier_refund', 'expense', 'income'))");
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount CHECK (amount <> 0)');
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_reason CHECK (type NOT IN ('expense', 'income') OR (reason IS NOT NULL AND length(trim(reason)) > 0))");
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            -- Un document de vente validé (ou converti, annulé) est figé.
            CREATE OR REPLACE FUNCTION diaba_sales_document_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'sales documents are never deleted' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                IF OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'cancelled sales document % is frozen', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.type, NEW.number, NEW.channel, NEW.warehouse_id, NEW.customer_id, NEW.customer_snapshot,
                    NEW.source_document_id, NEW.issue_date, NEW.due_date, NEW.valid_until, NEW.total_gross,
                    NEW.total_discount, NEW.total_excl_tax, NEW.total_tax, NEW.total, NEW.validated_by,
                    NEW.validated_at, NEW.created_by, NEW.tenant_id, NEW.idempotency_key, NEW.reason, NEW.notes, NEW.negative_stock_reason)
                   IS DISTINCT FROM
                   (OLD.type, OLD.number, OLD.channel, OLD.warehouse_id, OLD.customer_id, OLD.customer_snapshot,
                    OLD.source_document_id, OLD.issue_date, OLD.due_date, OLD.valid_until, OLD.total_gross,
                    OLD.total_discount, OLD.total_excl_tax, OLD.total_tax, OLD.total, OLD.validated_by,
                    OLD.validated_at, OLD.created_by, OLD.tenant_id, OLD.idempotency_key, OLD.reason, OLD.notes, OLD.negative_stock_reason) THEN
                    RAISE EXCEPTION 'validated sales document % is frozen', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                -- Seules évolutions permises : devis/commande convertis, devis/commande annulés, suivi des règlements.
                IF NEW.status <> OLD.status AND NOT (
                    OLD.status = 'validated' AND OLD.type IN ('quote', 'order') AND NEW.status IN ('converted', 'cancelled')
                ) THEN
                    RAISE EXCEPTION 'invalid status change on sales document %', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER sales_documents_frozen
                BEFORE UPDATE OR DELETE ON sales_documents
                FOR EACH ROW EXECUTE FUNCTION diaba_sales_document_frozen();

            -- Les lignes ne bougent que tant que le document est un brouillon.
            CREATE OR REPLACE FUNCTION diaba_sales_line_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM sales_documents
                    WHERE id = COALESCE(NEW.document_id, OLD.document_id);
                IF parent_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'lines of a non-draft sales document are frozen' USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;

            CREATE TRIGGER sales_document_lines_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON sales_document_lines
                FOR EACH ROW EXECUTE FUNCTION diaba_sales_line_frozen();

            -- Commande fournisseur confirmée : seuls l'avancement et le statut évoluent.
            CREATE OR REPLACE FUNCTION diaba_purchase_order_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'purchase orders are never deleted' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                IF (NEW.number, NEW.warehouse_id, NEW.supplier_id, NEW.total_excl_tax, NEW.total_tax, NEW.total,
                    NEW.validated_by, NEW.validated_at, NEW.tenant_id)
                   IS DISTINCT FROM
                   (OLD.number, OLD.warehouse_id, OLD.supplier_id, OLD.total_excl_tax, OLD.total_tax, OLD.total,
                    OLD.validated_by, OLD.validated_at, OLD.tenant_id) OR OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'confirmed purchase order % is frozen', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER purchase_orders_frozen
                BEFORE UPDATE OR DELETE ON purchase_orders
                FOR EACH ROW EXECUTE FUNCTION diaba_purchase_order_frozen();

            CREATE OR REPLACE FUNCTION diaba_purchase_order_line_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM purchase_orders
                    WHERE id = COALESCE(NEW.order_id, OLD.order_id);
                IF parent_status = 'draft' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                -- Après confirmation : seule la quantité reçue progresse.
                IF TG_OP = 'UPDATE' AND (NEW.product_id, NEW.quantity, NEW.unit_cost, NEW.tax_rate_basis_points,
                        NEW.line_excl_tax, NEW.line_tax, NEW.line_total, NEW.order_id, NEW.label, NEW.sku)
                    IS NOT DISTINCT FROM (OLD.product_id, OLD.quantity, OLD.unit_cost, OLD.tax_rate_basis_points,
                        OLD.line_excl_tax, OLD.line_tax, OLD.line_total, OLD.order_id, OLD.label, OLD.sku) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'lines of a confirmed purchase order are frozen' USING ERRCODE = 'check_violation';
            END $$;

            CREATE TRIGGER purchase_order_lines_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_lines
                FOR EACH ROW EXECUTE FUNCTION diaba_purchase_order_line_frozen();

            -- Facture fournisseur validée : montants figés, seul le suivi des règlements évolue.
            CREATE OR REPLACE FUNCTION diaba_supplier_invoice_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'supplier invoices are never deleted' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                IF OLD.status = 'cancelled' OR NEW.status <> OLD.status OR
                   (NEW.number, NEW.supplier_reference, NEW.supplier_id, NEW.warehouse_id, NEW.issue_date, NEW.due_date,
                    NEW.total_excl_tax, NEW.total_tax, NEW.total, NEW.validated_by, NEW.validated_at, NEW.tenant_id,
                    NEW.purchase_order_id, NEW.goods_receipt_id)
                   IS DISTINCT FROM
                   (OLD.number, OLD.supplier_reference, OLD.supplier_id, OLD.warehouse_id, OLD.issue_date, OLD.due_date,
                    OLD.total_excl_tax, OLD.total_tax, OLD.total, OLD.validated_by, OLD.validated_at, OLD.tenant_id,
                    OLD.purchase_order_id, OLD.goods_receipt_id) THEN
                    RAISE EXCEPTION 'validated supplier invoice % is frozen', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER supplier_invoices_frozen
                BEFORE UPDATE OR DELETE ON supplier_invoices
                FOR EACH ROW EXECUTE FUNCTION diaba_supplier_invoice_frozen();

            CREATE OR REPLACE FUNCTION diaba_supplier_invoice_line_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM supplier_invoices
                    WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
                IF parent_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'lines of a validated supplier invoice are frozen' USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;

            CREATE TRIGGER supplier_invoice_lines_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON supplier_invoice_lines
                FOR EACH ROW EXECUTE FUNCTION diaba_supplier_invoice_line_frozen();
        SQL);

        // Événements financiers et de stock : jamais modifiés ni supprimés.
        foreach (['payments', 'cash_movements', 'goods_receipts', 'goods_receipt_lines', 'supplier_returns', 'supplier_return_lines'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only
                    BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION diaba_append_only();
                CREATE TRIGGER {$table}_no_truncate
                    BEFORE TRUNCATE ON {$table}
                    FOR EACH STATEMENT EXECUTE FUNCTION diaba_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['cash_movements', 'payments', 'supplier_return_lines', 'supplier_returns', 'supplier_invoice_lines',
            'supplier_invoices', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders',
            'sales_document_lines', 'sales_documents', 'cash_sessions', 'cash_registers'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS diaba_sales_document_frozen();
            DROP FUNCTION IF EXISTS diaba_sales_line_frozen();
            DROP FUNCTION IF EXISTS diaba_purchase_order_frozen();
            DROP FUNCTION IF EXISTS diaba_purchase_order_line_frozen();
            DROP FUNCTION IF EXISTS diaba_supplier_invoice_frozen();
            DROP FUNCTION IF EXISTS diaba_supplier_invoice_line_frozen();
        SQL);
    }
};
