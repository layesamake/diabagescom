<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-02 — formules, souscriptions, options et rappels d'échéance.
 *
 * Montants en XOF, entiers (bigint). Une seule souscription non résiliée par
 * entreprise (index unique partiel). Les rappels sont idempotents grâce à
 * l'unicité (subscription_id, kind).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('warehouse_limit')->nullable(); // null = illimité
            $table->unsignedInteger('included_users');
            $table->boolean('accounting_enabled')->default(false);
            $table->bigInteger('price_monthly')->nullable(); // null = tarif non encore validé
            $table->bigInteger('price_yearly')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_limits_check CHECK ((warehouse_limit IS NULL OR warehouse_limit > 0) AND included_users > 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_prices_check CHECK ((price_monthly IS NULL OR price_monthly >= 0) AND (price_yearly IS NULL OR price_yearly >= 0))');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('billing_cycle', 8)->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('read_only_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('status_changed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'trial_ends_at']);
            $table->index(['status', 'read_only_ends_at']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'read_only', 'suspended', 'cancelled'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_check CHECK (billing_cycle IS NULL OR billing_cycle IN ('monthly', 'yearly'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_trial_check CHECK (status <> 'trialing' OR trial_ends_at IS NOT NULL)");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_read_only_check CHECK (status <> 'read_only' OR read_only_ends_at IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_current_per_tenant ON subscriptions (tenant_id) WHERE status <> 'cancelled'");

        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id');
            $table->string('addon_code', 32);
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('active');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->index(['subscription_id', 'status']);
            $table->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('subscriptions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE subscription_addons ADD CONSTRAINT subscription_addons_code_check CHECK (addon_code IN ('extra_user', 'extra_warehouse', 'extra_storage_gb'))");
        DB::statement('ALTER TABLE subscription_addons ADD CONSTRAINT subscription_addons_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE subscription_addons ADD CONSTRAINT subscription_addons_status_check CHECK (status IN ('active', 'cancelled'))");

        Schema::create('subscription_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->string('kind', 48);
            $table->timestampTz('sent_at');

            $table->unique(['subscription_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
