<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications configurables : choix par utilisateur, entreprise et catégorie
 * (dans l'application, e-mail), et registre du résumé quotidien envoyé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->timestampsTz();

            $table->primary(['tenant_id', 'user_id', 'category']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Un seul résumé par jour, utilisateur et entreprise, même si la tâche est rejouée.
        Schema::create('alert_digests', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('sent_on');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['tenant_id', 'user_id', 'sent_on']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_digests');
        Schema::dropIfExists('notification_preferences');
    }
};
