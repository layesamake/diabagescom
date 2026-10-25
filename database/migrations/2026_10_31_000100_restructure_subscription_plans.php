<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restructuration commerciale validée le 17/10/2026 : 3 formules au lieu de 4,
 * comptabilité et boutique en ligne désormais incluses dans toutes les offres
 * (activables par l'administrateur de l'entreprise, cf. migration suivante).
 *
 * « Essentiel Compta » est remplacée : son unique abonnée en production
 * bascule sur « Business » avant que la formule ne soit retirée de la vente
 * (elle ne peut pas être supprimée : une souscription historique y renvoie).
 * « Business Compta » devient « Premium », même ligne, mêmes abonnés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $essentialAccounting = DB::table('plans')->where('code', 'essential_accounting')->first();
            $business = DB::table('plans')->where('code', 'business')->first();

            if ($essentialAccounting !== null && $business !== null) {
                DB::table('subscriptions')
                    ->where('plan_id', $essentialAccounting->id)
                    ->where('status', '<>', 'cancelled')
                    ->update(['plan_id' => $business->id, 'updated_at' => now()]);
            }

            DB::table('plans')->where('code', 'essential')->update([
                'accounting_enabled' => true,
                'online_store_enabled' => true,
                'price_monthly' => 5000,
                'price_yearly' => 51000,
                'updated_at' => now(),
            ]);

            DB::table('plans')->where('code', 'business')->update([
                'accounting_enabled' => true,
                'online_store_enabled' => true,
                'price_monthly' => 12500,
                'price_yearly' => 120000,
                'updated_at' => now(),
            ]);

            DB::table('plans')->where('code', 'business_accounting')->update([
                'name' => 'Premium',
                'accounting_enabled' => true,
                'online_store_enabled' => true,
                'price_monthly' => 15000,
                'price_yearly' => 135000,
                'updated_at' => now(),
            ]);

            // Retirée de la vente (plus sélectionnable), conservée pour l'historique.
            DB::table('plans')->where('code', 'essential_accounting')->update([
                'active' => false,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Restructuration commerciale volontaire : pas de retour arrière automatique.
    }
};
