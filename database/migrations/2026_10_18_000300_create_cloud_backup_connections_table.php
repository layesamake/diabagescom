<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envoi automatique des sauvegardes vers le stockage en ligne du client
 * (Google Drive). Le jeton d'accès est chiffré par l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_backup_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('account_email', 255)->nullable();
            $table->text('refresh_token');
            $table->string('folder_id', 128)->nullable();
            $table->string('frequency', 16)->default('daily');
            $table->foreignUuid('connected_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'provider']);
        });
        DB::statement("ALTER TABLE cloud_backup_connections ADD CONSTRAINT cloud_backup_connections_provider_check CHECK (provider IN ('google_drive'))");
        DB::statement("ALTER TABLE cloud_backup_connections ADD CONSTRAINT cloud_backup_connections_frequency_check CHECK (frequency IN ('daily', 'weekly'))");

        Schema::table('tenant_exports', function (Blueprint $table) {
            $table->string('upload_to', 24)->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->string('upload_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_exports', function (Blueprint $table) {
            $table->dropColumn(['upload_to', 'uploaded_at', 'upload_error']);
        });
        Schema::dropIfExists('cloud_backup_connections');
    }
};
