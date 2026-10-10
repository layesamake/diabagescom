<?php

namespace Database\Seeders;

use App\Domain\Identity\SyncPermissions;
use App\Domain\Subscriptions\SyncPlans;
use Illuminate\Database\Seeder;

/**
 * Données de référence uniquement (permissions, rôles intégrés, formules).
 * Aucun compte n'est créé ici : utiliser diaba:create-platform-admin ou l'inscription.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SyncPermissions $permissions, SyncPlans $plans): void
    {
        $permissions();
        $plans();
    }
}
