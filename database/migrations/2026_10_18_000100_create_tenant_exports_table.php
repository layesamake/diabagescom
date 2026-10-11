<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sauvegardes par entreprise (archive ZIP téléchargeable, conservée 7 jours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->jsonb('summary')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE tenant_exports ADD CONSTRAINT tenant_exports_status_check CHECK (status IN ('pending', 'ready', 'failed', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
    }
};
