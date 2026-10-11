<?php

namespace App\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Backup\CloudBackupUploader;
use App\Domain\Backup\TenantExporter;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Prépare l'archive de sauvegarde d'une entreprise en arrière-plan.
 */
class BuildTenantExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $exportId)
    {
        $this->afterCommit();
    }

    public function handle(TenantExporter $exporter, TenantContext $context, AuditLog $audit, CloudBackupUploader $uploader): void
    {
        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->findOrFail($this->exportId);

        if ($export->status !== TenantExport::PENDING) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($export->tenant_id);

        $context->runAs($tenant, function () use ($exporter, $tenant, $export, $audit, $uploader): void {
            try {
                $result = $exporter->build($tenant, $export);
                $export->forceFill([
                    'status' => TenantExport::READY,
                    'path' => $result['path'],
                    'size' => $result['size'],
                    'sha256' => $result['sha256'],
                    'summary' => $result['summary'],
                    'completed_at' => now(),
                    'expires_at' => now()->addDays(TenantExport::KEEP_DAYS),
                ])->save();
                $audit->record('backup.export_ready', $export, metadata: ['size' => $result['size'], 'sha256' => $result['sha256']], actor: $export->author, tenant: $tenant);

                // Sauvegarde destinée au stockage en ligne du client : envoi immédiat.
                if ($export->upload_to !== null) {
                    $uploader->upload($export, $tenant);
                }
            } catch (Throwable $e) {
                report($e);
                $export->forceFill(['status' => TenantExport::FAILED, 'error' => 'La préparation a échoué.', 'completed_at' => now()])->save();
            }
        });
    }
}
