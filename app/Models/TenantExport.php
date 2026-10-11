<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sauvegarde d'une entreprise : archive ZIP préparée en arrière-plan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property string|null $path
 * @property int|null $size
 * @property string|null $sha256
 * @property array<string, int>|null $summary
 * @property string|null $error
 * @property string $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
class TenantExport extends Model
{
    use BelongsToTenant, HasUuids;

    public const string PENDING = 'pending';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    public const string EXPIRED = 'expired';

    /** Durée de conservation de l'archive sur le serveur. */
    public const int KEEP_DAYS = 7;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'summary' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
