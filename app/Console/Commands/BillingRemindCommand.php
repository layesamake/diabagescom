<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Factures de renouvellement et relances d'abonnement (idempotent).
 */
#[Signature('diaba:billing:remind')]
#[Description('Émet les factures de renouvellement et envoie les relances (idempotent)')]
class BillingRemindCommand extends Command
{
    public function handle(BillingReminders $reminders): int
    {
        $result = $reminders->run();
        $this->line("Renouvellements : {$result['renewals']} · relances : {$result['reminders']}");

        return self::SUCCESS;
    }
}
