<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilité et boutique en ligne sont désormais incluses dans toutes les
 * formules mais restent éteintes par défaut : l'administrateur de l'entreprise
 * les active lui-même dans Paramètres (docs validés le 17/10/2026).
 *
 * Les entreprises qui utilisent déjà la comptabilité grâce à leur formule
 * actuelle ne doivent pas se la voir couper silencieusement : on reprend leur
 * état existant au lieu de les forcer à « désactivé » par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('accounting_activated')->default(false)->after('status');
            $table->boolean('online_store_activated')->default(false)->after('accounting_activated');
        });

        DB::table('tenants')
            ->whereIn('id', function ($query): void {
                $query->select('subscriptions.tenant_id')
                    ->from('subscriptions')
                    ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                    ->where('subscriptions.status', '<>', 'cancelled')
                    ->where('plans.accounting_enabled', true);
            })
            ->update(['accounting_activated' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['accounting_activated', 'online_store_activated']);
        });
    }
};
