<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guide de démarrage : progression propre à chaque utilisateur dans chaque
 * entreprise (étapes passées ou cochées à la main, carte masquée, visite vue).
 * Les étapes détectables (un article existe, une vente a été faite…) se
 * calculent depuis les données métier et ne sont pas stockées ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_states', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('tour_seen_at')->nullable();
            $table->timestampsTz();

            $table->primary(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('guide_steps', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('step_key', 40);
            $table->string('status', 8);
            $table->timestampsTz();

            $table->primary(['tenant_id', 'user_id', 'step_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE guide_steps ADD CONSTRAINT guide_steps_status CHECK (status IN ('done', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_steps');
        Schema::dropIfExists('guide_states');
    }
};
