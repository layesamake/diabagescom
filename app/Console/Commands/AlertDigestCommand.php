<?php

namespace App\Console\Commands;

use App\Domain\Notifications\SendAlertDigests;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Résumé quotidien des points à traiter, pour les membres qui l'ont demandé.
 */
#[Signature('diaba:alerts:digest')]
#[Description('Envoie le résumé quotidien des points à traiter (idempotent)')]
class AlertDigestCommand extends Command
{
    public function handle(SendAlertDigests $digests): int
    {
        $this->line('Résumés envoyés : '.$digests());

        return self::SUCCESS;
    }
}
