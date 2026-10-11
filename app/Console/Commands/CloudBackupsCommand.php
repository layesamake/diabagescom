<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Jobs\BuildTenantExport;
use App\Models\CloudBackupConnection;
use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sauvegardes automatiques vers le Google Drive des entreprises qui l'ont
 * relié, selon la fréquence choisie (quotidienne ou hebdomadaire).
 */
#[Signature('diaba:backups:cloud')]
#[Description('Prépare et envoie les sauvegardes automatiques vers Google Drive')]
class CloudBackupsCommand extends Command
{
    public function handle(TenantContext $context): int
    {
        $count = 0;

        CloudBackupConnection::query()->withoutGlobalScope(TenantScope::class)->orderBy('created_at')
            ->each(function (CloudBackupConnection $connection) use ($context, &$count): void {
                $tenant = Tenant::query()->where('id', $connection->tenant_id)->where('status', 'active')->first();

                if ($tenant === null || ! $connection->isDue()) {
                    return;
                }

                $context->runAs($tenant, function () use ($connection, &$count): void {
                    if (TenantExport::query()->where('status', TenantExport::PENDING)->where('created_at', '>', now()->subHour())->exists()) {
                        return;
                    }

                    $export = new TenantExport;
                    $export->forceFill(['status' => TenantExport::PENDING, 'created_by' => $connection->connected_by, 'upload_to' => $connection->provider])->save();
                    // Marqué tout de suite : une tâche lente ne déclenche pas de doublon à l'heure suivante.
                    $connection->forceFill(['last_run_at' => now()])->save();
                    BuildTenantExport::dispatch($export->id);
                    $count++;
                });
            });

        $this->line("Sauvegardes automatiques lancées : {$count}");

        return self::SUCCESS;
    }
}
