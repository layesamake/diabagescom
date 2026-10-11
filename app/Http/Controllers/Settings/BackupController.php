<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\AuditLog;
use App\Domain\Backup\GoogleDrive;
use App\Http\Controllers\Controller;
use App\Jobs\BuildTenantExport;
use App\Models\CloudBackupConnection;
use App\Models\TenantExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sauvegarde des données de l'entreprise (administrateur uniquement) :
 * préparation en arrière-plan, téléchargement audité, conservation 7 jours.
 */
class BackupController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('organization.manage');

        $exports = TenantExport::query()->with('author:id,name')->latest()->limit(10)->get()
            ->map(fn (TenantExport $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'size' => $e->size,
                'rows' => $e->summary !== null ? array_sum($e->summary) : null,
                'author' => $e->author->name,
                'created_at' => $e->created_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'upload_to' => $e->upload_to,
                'uploaded_at' => $e->uploaded_at?->toIso8601String(),
                'upload_error' => $e->upload_error,
            ]);
        $drive = CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->first();

        return Inertia::render('settings/Backup', [
            'exports' => $exports,
            'keepDays' => TenantExport::KEEP_DAYS,
            'drive' => [
                'available' => GoogleDrive::configured(),
                'keep' => CloudBackupConnection::KEEP,
                'connection' => $drive === null ? null : [
                    'email' => $drive->account_email,
                    'frequency' => $drive->frequency,
                    'last_run_at' => $drive->last_run_at?->toIso8601String(),
                    'last_status' => $drive->last_status,
                    'last_error' => $drive->last_error,
                ],
            ],
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('organization.manage');

        // Une seule préparation à la fois par entreprise.
        if (TenantExport::query()->where('status', TenantExport::PENDING)->where('created_at', '>', now()->subHour())->exists()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('organization.backup.already_pending')]);

            return back();
        }

        $export = new TenantExport;
        $export->forceFill(['status' => TenantExport::PENDING, 'created_by' => $request->user()?->id])->save();
        $audit->record('backup.export_requested', $export);
        BuildTenantExport::dispatch($export->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.backup.requested')]);

        return back();
    }

    public function download(TenantExport $export, AuditLog $audit): StreamedResponse
    {
        Gate::authorize('organization.manage');
        abort_unless($export->status === TenantExport::READY && $export->path !== null && Storage::disk('local')->exists($export->path), 404);

        $audit->record('backup.export_downloaded', $export, metadata: ['sha256' => $export->sha256]);
        $name = 'sauvegarde-'.str($export->tenant->display_name)->slug().'-'.$export->created_at?->format('Y-m-d-His').'.zip';

        return Storage::disk('local')->download($export->path, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
