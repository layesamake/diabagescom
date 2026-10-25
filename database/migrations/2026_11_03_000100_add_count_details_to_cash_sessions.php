<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Détail du comptage de la caisse (mode simple) : billets et pièces comptés à
 * l'ouverture et à la fermeture, sous la forme
 * {"opening": {"10000": 1, "500": 2}, "closing": {…}}.
 *
 * Le montant (opening_amount, counted_amount) reste la référence comptable ;
 * ce détail sert à la traçabilité et à comprendre un écart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->jsonb('count_details')->nullable()->after('closing_note');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropColumn('count_details');
        });
    }
};
