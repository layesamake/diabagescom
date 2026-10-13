<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordonnées et mentions légales de l'entreprise, reprises sur les documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('ninea', 30)->nullable();
            $table->string('rccm', 40)->nullable();
            $table->string('document_footer', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'address', 'city', 'ninea', 'rccm', 'document_footer']);
        });
    }
};
