<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantScope;
use App\Models\TenantExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime du serveur les archives de sauvegarde arrivées à expiration
 * (données confidentielles : on ne les garde pas plus que nécessaire).
 */
#[Signature('diaba:backups:prune')]
#[Description('Supprime les archives de sauvegarde d’entreprise expirées')]
class PruneTenantExportsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        TenantExport::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', TenantExport::READY)->where('expires_at', '<', now())
            ->each(function (TenantExport $export) use (&$count): void {
                if ($export->path !== null) {
                    Storage::disk('local')->delete($export->path);
                }

                $export->forceFill(['status' => TenantExport::EXPIRED, 'path' => null])->save();
                $count++;
            });

        // Préparations bloquées (tâche interrompue) : marquées en échec.
        TenantExport::query()->withoutGlobalScope(TenantScope::class)
            ->where('status', TenantExport::PENDING)->where('created_at', '<', now()->subHours(2))
            ->update(['status' => TenantExport::FAILED, 'error' => 'Préparation interrompue.', 'updated_at' => now()]);

        $this->line("Archives expirées supprimées : {$count}");

        return self::SUCCESS;
    }
}
