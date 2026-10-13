<?php

namespace App\Console\Commands;

use App\Domain\Billing\ReconcilePayments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rapprochement des paiements en ligne en attente (idempotent).
 */
#[Signature('diaba:billing:reconcile')]
#[Description('Interroge les prestataires sur les paiements en attente et annule ceux restés sans issue (idempotent)')]
class BillingReconcileCommand extends Command
{
    public function handle(ReconcilePayments $reconcile): int
    {
        $r = $reconcile();
        $this->line("Vérifiés : {$r['checked']} · réglés : {$r['settled']} · expirés : {$r['expired']} · erreurs : {$r['errors']}");

        return $r['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
