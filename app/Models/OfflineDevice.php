<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appareil (navigateur / PWA installée) autorisé à synchroniser des commandes
 * hors connexion. Révocable par son utilisateur ou un administrateur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $device_key
 * @property string $label
 * @property string|null $user_agent
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
class OfflineDevice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
