<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements manuels (virement, Wave vers le numéro de DIABA GESCOM) et avoirs.
 *
 * - saas_payments : le client déclare son paiement (référence de transaction et
 *   capture ou reçu facultatif) ; le super-administrateur valide ou refuse.
 * - saas_credit_notes : avoir sur une facture payée (remboursement, geste
 *   commercial), numéroté, plafonné au montant de la facture, jamais modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->string('declared_reference', 120)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->timestampTz('declared_at')->nullable();
            $table->foreignUuid('declared_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS saas_credit_note_number_seq');

        Schema::create('saas_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->string('number', 32)->unique();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('XOF');
            $table->string('reason', 500);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('saas_invoices')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE saas_credit_notes ADD CONSTRAINT saas_credit_notes_amount CHECK (amount > 0 AND currency = 'XOF')");
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_credit_notes');
        DB::statement('DROP SEQUENCE IF EXISTS saas_credit_note_number_seq');

        Schema::table('saas_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('declared_by');
            $table->dropColumn(['declared_reference', 'proof_path', 'declared_at']);
        });
    }
};
