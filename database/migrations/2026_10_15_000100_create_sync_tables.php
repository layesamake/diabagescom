<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-06 — PWA hors connexion.
 *
 * - offline_devices : appareils enregistrés par un utilisateur, révocables ;
 * - sync_commands : journal append-only des commandes reçues d'un appareil.
 *   (tenant_id, command_id) unique : rejouer une commande renvoie son résultat
 *   d'origine, jamais un second traitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('device_key');
            $table->string('label', 120);
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'device_key']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('sync_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('device_id');
            $table->uuid('command_id');
            $table->string('type', 32);
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->string('status', 20);
            $table->jsonb('result')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('client_created_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('offline_devices')->restrictOnDelete();
            $table->unique(['tenant_id', 'command_id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE sync_commands ADD CONSTRAINT sync_commands_status CHECK (status IN ('accepted', 'rejected', 'conflict', 'requires_review'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sync_commands_append_only
                BEFORE UPDATE OR DELETE ON sync_commands
                FOR EACH ROW EXECUTE FUNCTION diaba_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_commands');
        Schema::dropIfExists('offline_devices');
    }
};
