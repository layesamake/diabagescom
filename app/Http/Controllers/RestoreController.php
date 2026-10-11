<?php

namespace App\Http\Controllers;

use App\Domain\Backup\InvalidBackup;
use App\Domain\Backup\TenantRestorer;
use App\Jobs\RestoreTenantBackup;
use App\Models\TenantRestore;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Restaurer une sauvegarde : l'archive est vérifiée dès l'envoi (signature,
 * intégrité, appartenance), puis restaurée en arrière-plan dans une nouvelle
 * entreprise dont l'utilisateur devient administrateur.
 */
class RestoreController extends Controller
{
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('onboarding/RestoreBackup', [
            'restores' => TenantRestore::query()->with('tenant:id,display_name')->where('user_id', $user->id)
                ->latest()->limit(5)->get()
                ->map(fn (TenantRestore $r) => [
                    'id' => $r->id,
                    'status' => $r->status,
                    'file_name' => $r->file_name,
                    'source_name' => $r->source_name,
                    'tenant' => $r->tenant?->only(['id', 'display_name']),
                    'rows' => $r->summary !== null ? array_sum($r->summary) : null,
                    'error' => $r->error,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
            'maxMb' => TenantRestore::MAX_MB,
        ]);
    }

    public function store(Request $request, TenantRestorer $restorer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['backup' => ['required', 'file', 'mimes:zip', 'max:'.TenantRestore::MAX_MB * 1024]]);

        if (TenantRestore::query()->where('user_id', $user->id)->where('status', TenantRestore::PENDING)->where('created_at', '>', now()->subHour())->exists()) {
            throw ValidationException::withMessages(['backup' => __('organization.restore.errors.pending')]);
        }

        $file = $request->file('backup');
        $path = $file->storeAs('restores', Str::uuid7().'.zip', 'local');

        if ($path === false) {
            throw ValidationException::withMessages(['backup' => __('organization.restore.errors.failed')]);
        }

        try {
            $manifest = $restorer->inspect(Storage::disk('local')->path($path), $user);
        } catch (InvalidBackup $e) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }

        $restore = new TenantRestore;
        $restore->forceFill([
            'user_id' => $user->id,
            'status' => TenantRestore::PENDING,
            'path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 190),
            'source_name' => Str::limit((string) ($manifest['tenant']['display_name'] ?? ''), 190),
        ])->save();
        RestoreTenantBackup::dispatch($restore->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.restore.requested')]);

        return back();
    }
}
