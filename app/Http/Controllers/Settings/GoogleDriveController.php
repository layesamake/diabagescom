<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\AuditLog;
use App\Domain\Backup\GoogleDrive;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\BuildTenantExport;
use App\Models\CloudBackupConnection;
use App\Models\TenantExport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

/**
 * Relier le Google Drive de l'entreprise pour y recevoir ses sauvegardes
 * (administrateur uniquement). Le paramètre « state » lie le retour de Google
 * à la session et à l'entreprise qui ont demandé la connexion.
 */
class GoogleDriveController extends Controller
{
    private const string SESSION_STATE = 'google_drive.oauth';

    public function connect(Request $request, GoogleDrive $drive, TenantContext $context): RedirectResponse
    {
        Gate::authorize('organization.manage');

        if (! GoogleDrive::configured()) {
            return $this->back('error', __('organization.cloud.errors.not_configured'));
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, ['state' => $state, 'tenant_id' => $context->tenantId()]);

        // Lien classique (pas Inertia) : le navigateur part sur la page d'autorisation Google.
        return redirect()->away($drive->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleDrive $drive, TenantContext $context, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $expected = $request->session()->pull(self::SESSION_STATE);

        if (! is_array($expected) || ! hash_equals((string) $expected['state'], (string) $request->query('state'))
            || $expected['tenant_id'] !== $context->tenantId()) {
            return $this->back('error', __('organization.cloud.errors.failed'));
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->back('info', __('organization.cloud.errors.denied'));
        }

        try {
            $tokens = $drive->exchange((string) $request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return $this->back('error', __('organization.cloud.errors.failed'));
        }

        /** @var User $user */
        $user = $request->user();
        $connection = CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->first() ?? new CloudBackupConnection;
        $previous = $connection->exists ? $connection->refresh_token : null;
        $connection->forceFill([
            'provider' => CloudBackupConnection::GOOGLE_DRIVE,
            'account_email' => $tokens['email'],
            'refresh_token' => $tokens['refresh_token'],
            'connected_by' => $user->id,
            'last_status' => null,
            'last_error' => null,
        ])->save();

        if ($previous !== null && $previous !== $tokens['refresh_token']) {
            rescue(fn () => $drive->revoke($previous), report: false);
        }

        $audit->record('backup.cloud_connected', $connection, after: ['provider' => $connection->provider, 'account' => $tokens['email']]);

        return $this->back('success', __('organization.cloud.connected', ['email' => $tokens['email'] ?? 'Google']));
    }

    public function update(Request $request, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate(['frequency' => ['required', Rule::in(CloudBackupConnection::FREQUENCIES)]]);
        $connection = CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->firstOrFail();
        $before = $connection->frequency;
        $connection->forceFill(['frequency' => $data['frequency']])->save();
        $audit->record('backup.cloud_updated', $connection, before: ['frequency' => $before], after: ['frequency' => $data['frequency']]);

        return $this->back('success', __('organization.cloud.saved'));
    }

    /**
     * Prépare tout de suite une sauvegarde et l'envoie vers Google Drive.
     */
    public function send(Request $request): RedirectResponse
    {
        Gate::authorize('organization.manage');
        CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->firstOrFail();

        if (TenantExport::query()->where('status', TenantExport::PENDING)->where('created_at', '>', now()->subHour())->exists()) {
            return $this->back('info', __('organization.backup.already_pending'));
        }

        $export = new TenantExport;
        $export->forceFill(['status' => TenantExport::PENDING, 'created_by' => $request->user()?->id, 'upload_to' => CloudBackupConnection::GOOGLE_DRIVE])->save();
        BuildTenantExport::dispatch($export->id);

        return $this->back('success', __('organization.cloud.sending'));
    }

    public function destroy(GoogleDrive $drive, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $connection = CloudBackupConnection::query()->where('provider', CloudBackupConnection::GOOGLE_DRIVE)->firstOrFail();

        // Retrait de l'accès chez Google ; les fichiers déjà envoyés restent au client.
        rescue(fn () => $drive->revoke($connection->refresh_token), report: false);
        $audit->record('backup.cloud_disconnected', $connection, before: ['provider' => $connection->provider, 'account' => $connection->account_email]);
        $connection->delete();

        return $this->back('success', __('organization.cloud.disconnected'));
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->route('backup.index');
    }
}
