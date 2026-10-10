<?php

namespace Database\Seeders;

use App\Domain\Identity\SyncPermissions;
use Illuminate\Database\Seeder;

/**
 * Données de référence uniquement (permissions et rôles intégrés).
 * Aucun compte n'est créé ici : utiliser diaba:create-platform-admin ou l'inscription.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SyncPermissions $sync): void
    {
        $sync();
    }
}
