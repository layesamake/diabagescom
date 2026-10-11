<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restauration d'une sauvegarde dans une nouvelle entreprise.
 *
 * @property string $id
 * @property string $user_id
 * @property string $status
 * @property string|null $path
 * @property string $file_name
 * @property string|null $source_name
 * @property string|null $tenant_id
 * @property array<string, int>|null $summary
 * @property string|null $error
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 */
class TenantRestore extends Model
{
    use HasUuids;

    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    /** Taille maximale d'une sauvegarde envoyée (Mo). */
    public const int MAX_MB = 100;

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
