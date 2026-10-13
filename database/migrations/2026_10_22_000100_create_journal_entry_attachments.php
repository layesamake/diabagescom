<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces justificatives d'une écriture comptable (facture, relevé, reçu…).
 * Une pièce peut être ajoutée à une écriture validée sans en modifier le
 * contenu comptable ; elle n'est retirée que d'un brouillon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('entry_id');
            $table->string('original_name', 180);
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('entry_id');
        });
        DB::statement("ALTER TABLE journal_entry_attachments ADD CONSTRAINT journal_entry_attachments_mime CHECK (mime IN ('application/pdf', 'image/jpeg', 'image/png'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_attachments');
    }
};
