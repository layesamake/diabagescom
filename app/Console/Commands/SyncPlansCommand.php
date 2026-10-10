<?php

namespace App\Console\Commands;

use App\Domain\Subscriptions\SyncPlans;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('diaba:sync-plans')]
#[Description('Crée les formules par défaut absentes, sans modifier les formules existantes.')]
class SyncPlansCommand extends Command
{
    public function handle(SyncPlans $sync): int
    {
        $this->components->info(sprintf('%d formule(s) créée(s).', $sync()));

        return self::SUCCESS;
    }
}
