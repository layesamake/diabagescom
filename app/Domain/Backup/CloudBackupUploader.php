<?php

namespace App\Domain\Backup;

use App\Domain\Audit\AuditLog;
use App\Models\CloudBackupConnection;
use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dépose une sauvegarde prête dans le Google Drive relié par l'entreprise,
 * puis ne garde que les plus récentes. Un échec est enregistré et affiché,
 * jamais bloquant pour l'archive locale.
 */
final class CloudBackupUploader
{
    public function __construct(
        private readonly GoogleDrive $drive,
        private readonly AuditLog $audit,
    ) {}

    public function upload(TenantExport $export, Tenant $tenant): void
    {
        $connection = CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->first();

        if ($connection === null || $export->path === null) {
            $export->forceFill(['upload_error' => __('organization.cloud.errors.not_connected')])->save();

            return;
        }

        try {
            $folder = $this->drive->ensureFolder($connection, 'DIABA GESCOM – '.$tenant->display_name);
            $name = 'sauvegarde-'.Str::slug($tenant->display_name).'-'.$export->created_at?->timezone($tenant->timezone)->format('Y-m-d-His').'.zip';
            $this->drive->upload($connection, $folder, Storage::disk('local')->path($export->path), $name);
            $this->drive->prune($connection, $folder, CloudBackupConnection::KEEP);

            $export->forceFill(['uploaded_at' => now(), 'upload_error' => null])->save();
            $connection->forceFill(['last_run_at' => now(), 'last_status' => 'ok', 'last_error' => null])->save();
            $this->audit->record('backup.uploaded', $export, metadata: ['provider' => $connection->provider], actor: $export->author, tenant: $tenant);
        } catch (Throwable $e) {
            report($e);
            $message = __($e->getMessage() === 'revoked' ? 'organization.cloud.errors.revoked' : 'organization.cloud.errors.upload');
            $export->forceFill(['upload_error' => $message])->save();
            $connection->forceFill(['last_run_at' => now(), 'last_status' => 'failed', 'last_error' => $message])->save();
        }
    }
}
