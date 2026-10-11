<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-08 — facturation SaaS et paiements.
 *
 * - saas_invoices : facture d'abonnement (une seule facture ouverte par entreprise) ;
 * - saas_payments : tentatives de paiement ; au plus UN paiement réussi par
 *   facture (index unique partiel) : un webhook rejoué ou un second événement
 *   ne peut jamais payer deux fois ni prolonger deux fois l'abonnement ;
 * - payment_provider_events : webhooks reçus, dédupliqués par
 *   (prestataire, identifiant d'événement), charge utile expurgée ;
 * - support_grants : accès support ponctuel, motivé, limité dans le temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS saas_invoice_number_seq');

        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 32)->unique();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('billing_cycle', 16);
            $table->jsonb('lines');
            $table->bigInteger('total');
            $table->string('currency', 3)->default('XOF');
            $table->string('status', 16)->default('open');
            $table->date('due_on');
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedSmallInteger('reminder_stage')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'due_on']);
        });
        DB::statement("ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_status CHECK (status IN ('open', 'paid', 'void'))");
        DB::statement("ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_cycle CHECK (billing_cycle IN ('monthly', 'yearly'))");
        DB::statement("ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_total CHECK (total > 0 AND currency = 'XOF')");
        DB::statement("ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_paid CHECK (status <> 'paid' OR (paid_at IS NOT NULL AND period_start IS NOT NULL AND period_end IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX saas_invoices_one_open ON saas_invoices (tenant_id) WHERE status = 'open'");

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->string('provider', 24);
            $table->string('status', 24)->default('pending');
            $table->bigInteger('amount');
            $table->string('external_reference', 120);
            $table->string('checkout_url', 500)->nullable();
            $table->string('proof_reference', 120)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('saas_invoices')->restrictOnDelete();
            $table->unique(['provider', 'external_reference']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE saas_payments ADD CONSTRAINT saas_payments_status CHECK (status IN ('pending', 'pending_manual_review', 'succeeded', 'failed', 'cancelled', 'requires_review'))");
        DB::statement('ALTER TABLE saas_payments ADD CONSTRAINT saas_payments_amount CHECK (amount > 0)');
        DB::statement("CREATE UNIQUE INDEX saas_payments_one_success ON saas_payments (invoice_id) WHERE status = 'succeeded'");

        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 24);
            $table->string('external_event_id', 120);
            $table->string('event_type', 60)->nullable();
            $table->boolean('signature_valid');
            $table->jsonb('payload');
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->uuid('payment_id')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provider', 'external_event_id']);
            $table->index('payment_id');
        });
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_events_status CHECK (status IN ('received', 'processed', 'ignored', 'rejected', 'failed'))");

        Schema::create('support_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_grants');
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('saas_payments');
        Schema::dropIfExists('saas_invoices');
        DB::statement('DROP SEQUENCE IF EXISTS saas_invoice_number_seq');
    }
};
