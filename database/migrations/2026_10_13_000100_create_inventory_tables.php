<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-04 — ledger de stock.
 *
 * - stock_operations / inventory_movements : append-only (trigger). Tout changement
 *   de stock est expliqué par un mouvement immuable rattaché à une opération.
 * - Idempotence : (tenant_id, idempotency_key) unique sur les opérations.
 * - inventory_balances : projection mise à jour dans la même transaction que
 *   les mouvements ; jamais modifiée hors du service StockLedger.
 * - Quantités numeric(15,3) (jamais de flottant) ; coûts en XOF entiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type', 32);
            $table->string('idempotency_key', 100);
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->boolean('negative_stock_allowed')->default(false);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('operation_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->string('type', 32);
            $table->decimal('quantity_delta', 15, 3);
            $table->bigInteger('unit_cost'); // coût unitaire XOF appliqué (entrée : coût d'achat ; sortie : coût moyen)
            $table->decimal('balance_after', 15, 3);
            $table->bigInteger('average_cost_after');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'operation_id'])->references(['tenant_id', 'id'])->on('stock_operations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->index(['tenant_id', 'warehouse_id', 'product_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index('operation_id');
        });
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_nonzero CHECK (quantity_delta <> 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_cost CHECK (unit_cost >= 0 AND average_cost_after >= 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type CHECK (type IN (
            'initial', 'receipt', 'sale', 'customer_return', 'supplier_return',
            'transfer_out', 'transfer_in', 'adjustment_in', 'adjustment_out', 'count_adjustment'))");

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->decimal('quantity_on_hand', 15, 3)->default(0);
            $table->decimal('quantity_reserved', 15, 3)->default(0);
            $table->bigInteger('average_cost')->default(0);
            $table->uuid('last_movement_id')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['warehouse_id', 'product_id']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->index(['tenant_id', 'product_id']);
        });
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_cost CHECK (average_cost >= 0 AND quantity_reserved >= 0)');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->nullable();
            $table->uuid('source_warehouse_id');
            $table->uuid('destination_warehouse_id');
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->string('receipt_note', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('shipped_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('shipped_at')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'source_warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct CHECK (source_warehouse_id <> destination_warehouse_id)');
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status CHECK (status IN ('draft', 'in_transit', 'received', 'cancelled'))");

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('transfer_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 15, 3);
            $table->decimal('quantity_received', 15, 3)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'transfer_id'])->references(['tenant_id', 'id'])->on('stock_transfers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['transfer_id', 'product_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_qty CHECK (quantity > 0 AND (quantity_received IS NULL OR (quantity_received >= 0 AND quantity_received <= quantity)))');

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->nullable();
            $table->uuid('warehouse_id');
            $table->string('status', 16)->default('draft');
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
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE inventory_counts ADD CONSTRAINT inventory_counts_status CHECK (status IN ('draft', 'validated', 'cancelled'))");

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('count_id');
            $table->uuid('product_id');
            $table->decimal('counted_quantity', 15, 3);
            $table->decimal('theoretical_quantity', 15, 3)->nullable(); // figée à la validation
            $table->decimal('variance', 15, 3)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'count_id'])->references(['tenant_id', 'id'])->on('inventory_counts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['count_id', 'product_id']);
        });
        DB::statement('ALTER TABLE inventory_count_lines ADD CONSTRAINT inventory_count_lines_qty CHECK (counted_quantity >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_operations_append_only
                BEFORE UPDATE OR DELETE ON stock_operations
                FOR EACH ROW EXECUTE FUNCTION diaba_append_only();
            CREATE TRIGGER stock_operations_no_truncate
                BEFORE TRUNCATE ON stock_operations
                FOR EACH STATEMENT EXECUTE FUNCTION diaba_append_only();
            CREATE TRIGGER inventory_movements_append_only
                BEFORE UPDATE OR DELETE ON inventory_movements
                FOR EACH ROW EXECUTE FUNCTION diaba_append_only();
            CREATE TRIGGER inventory_movements_no_truncate
                BEFORE TRUNCATE ON inventory_movements
                FOR EACH STATEMENT EXECUTE FUNCTION diaba_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('stock_operations');
    }
};
