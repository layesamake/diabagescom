<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'achat : un besoin exprimé par un collaborateur (brouillon → soumise →
 * approuvée ou refusée), puis transformé en commande fournisseur par les achats.
 * Aucun montant ni fournisseur à ce stade : la demande dit « quoi » et « combien ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->uuid('warehouse_id');
            $table->date('needed_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->uuid('order_id')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'ordered', 'cancelled'))");
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_numbered CHECK (status IN ('draft', 'cancelled') OR number IS NOT NULL)");
        // Une commande n'est issue que d'une seule demande.
        DB::statement('CREATE UNIQUE INDEX purchase_requests_one_order ON purchase_requests (order_id) WHERE order_id IS NOT NULL');

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('request_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('product_id');
            $table->string('sku', 64);
            $table->string('label', 200);
            $table->string('unit_code', 16);
            $table->decimal('quantity', 15, 3);
            $table->string('note', 200)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'request_id'])->references(['tenant_id', 'id'])->on('purchase_requests')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('request_id');
        });
        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT purchase_request_lines_quantity CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
