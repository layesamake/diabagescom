<?php

namespace App\Jobs;

use App\Domain\Backup\InvalidBackup;
use App\Domain\Backup\TenantRestorer;
use App\Models\Plan;
use App\Models\TenantRestore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Restaure une sauvegarde envoyée dans une nouvelle entreprise ; le fichier
 * envoyé est supprimé ensuite, que la restauration réussisse ou non.
 */
class RestoreTenantBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $restoreId)
    {
        $this->afterCommit();
    }

    public function handle(TenantRestorer $restorer): void
    {
        $restore = TenantRestore::query()->with('user')->findOrFail($this->restoreId);

        if ($restore->status !== TenantRestore::PENDING || $restore->path === null) {
            return;
        }

        try {
            $plan = Plan::query()->where('code', config('diaba.subscriptions.default_plan'))->where('active', true)->firstOrFail();
            $result = $restorer->restore(Storage::disk('local')->path($restore->path), $restore->user, $plan);
            $restore->forceFill(['status' => TenantRestore::DONE, 'tenant_id' => $result['tenant']->id, 'summary' => $result['counts']]);
        } catch (InvalidBackup $e) {
            $restore->forceFill(['status' => TenantRestore::FAILED, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            $restore->forceFill(['status' => TenantRestore::FAILED, 'error' => __('organization.restore.errors.failed')]);
        } finally {
            Storage::disk('local')->delete($restore->path);
            $restore->forceFill(['path' => null, 'completed_at' => now()])->save();
        }
    }
}
