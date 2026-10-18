<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mode d'interface par appartenance (lot 1 du mode simple).
 *
 * Le choix est porté par l'appartenance et non par l'utilisateur : un même
 * compte peut être vendeur (mode simple) dans une entreprise et gestionnaire
 * (mode expert) dans une autre.
 *
 * Par défaut : simple pour les rôles de terrain (vendeur, gestionnaire de
 * dépôt), expert pour les autres — personne ne change d'interface sans
 * l'avoir demandé.
 */
return new class extends Migration
{
    private const array FIELD_ROLES = ['cashier', 'warehouse_manager'];

    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->string('ui_mode', 8)->default('expert')->after('status');
        });

        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_ui_mode_check CHECK (ui_mode IN ('simple', 'expert'))");

        DB::table('tenant_memberships')
            ->whereIn('role_id', fn ($query) => $query->select('id')->from('roles')->whereIn('code', self::FIELD_ROLES))
            ->update(['ui_mode' => 'simple']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT tenant_memberships_ui_mode_check');

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropColumn('ui_mode');
        });
    }
};
