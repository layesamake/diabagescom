<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Système audio du mode simple (lot 4) :
 * - une langue de voix par entreprise ;
 * - les morceaux enregistrés pour chaque phrase, dans cette langue ;
 * - le nom d'un article ou d'un client dit au micro, pour remplacer
 *   {article}/{client} dans les phrases qui en ont besoin.
 *
 * Une phrase peut contenir 0, 1 ou 2 variables (ex. VEN-05 « Total :
 * {montant}. » en a une ; ACH-06 « Il manque {nombre} {article}. » en a
 * deux, sans texte entre elles). On modélise donc le morceau par un index
 * de segment plutôt qu'un simple « avant/après » : segment 0 = texte avant
 * la première variable (ou la phrase entière si elle n'en a aucune),
 * segment N = texte après la dernière variable. App\Domain\Audio\PhraseCatalog
 * fait l'unique source de vérité sur le nombre de segments attendus par
 * phrase ; cette table ne contient que les fichiers réellement enregistrés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('voice_language', 16)->nullable()->after('locale');
        });

        Schema::create('audio_clips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 16);
            $table->string('language', 16);
            $table->unsignedTinyInteger('segment_index');
            $table->string('file_path');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'language', 'segment_index'], 'audio_clips_unique_segment');
            $table->index(['tenant_id', 'language']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_audio_path')->nullable()->after('name');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('name_audio_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('name_audio_path');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('name_audio_path');
        });

        Schema::dropIfExists('audio_clips');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('voice_language');
        });
    }
};
