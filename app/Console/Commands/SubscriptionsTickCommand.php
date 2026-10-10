<?php

namespace App\Console\Commands;

use App\Domain\Subscriptions\SendTrialNotices;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('diaba:subscriptions:tick')]
#[Description('Applique les transitions d’abonnement échues et envoie les rappels de fin d’essai (idempotent).')]
class SubscriptionsTickCommand extends Command
{
    public function handle(SubscriptionLifecycle $lifecycle, SendTrialNotices $notices): int
    {
        $changes = $lifecycle->applyDueTransitions();
        $sent = $notices();

        Log::info('subscriptions.tick', ['transitions' => count($changes), 'notices' => $sent]);
        $this->components->info(sprintf('%d transition(s), %d rappel(s).', count($changes), $sent));

        return self::SUCCESS;
    }
}
