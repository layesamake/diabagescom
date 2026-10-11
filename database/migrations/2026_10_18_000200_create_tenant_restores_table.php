<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurations de sauvegarde demandées par un utilisateur (création d'une
 * nouvelle entreprise ; pas de tenant_id tant qu'elle n'existe pas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_restores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('path', 255)->nullable();
            $table->string('file_name', 200);
            $table->string('source_name', 200)->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('summary')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE tenant_restores ADD CONSTRAINT tenant_restores_status_check CHECK (status IN ('pending', 'done', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_restores');
    }
};
