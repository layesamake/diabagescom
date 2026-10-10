<?php

namespace App\Console\Commands;

use App\Domain\Identity\SyncPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('diaba:sync-permissions')]
#[Description('Synchronise permissions et rôles intégrés avec le catalogue (idempotent).')]
class SyncPermissionsCommand extends Command
{
    public function handle(SyncPermissions $sync): int
    {
        $sync();
        $this->components->info('Permissions et rôles intégrés synchronisés.');

        return self::SUCCESS;
    }
}
