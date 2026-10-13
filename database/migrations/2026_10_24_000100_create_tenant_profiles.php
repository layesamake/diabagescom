<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche de l'entreprise cliente : identité visuelle (logo, cachet/signature,
 * devanture), régime de TVA et localisation Google Maps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_profiles', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->string('vat_regime', 16)->default('real');
            $table->string('maps_url', 500)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('stamp_path', 255)->nullable();
            $table->string('cover_path', 255)->nullable();
            // Change à chaque remplacement d'image : force le rafraîchissement des caches.
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE tenant_profiles ADD CONSTRAINT tenant_profiles_vat_regime CHECK (vat_regime IN ('real', 'cgu', 'exempt'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_profiles');
    }
};
