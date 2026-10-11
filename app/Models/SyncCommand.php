<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Commande reçue d'un appareil hors connexion, avec son résultat définitif
 * (append-only : un rejeu renvoie ce résultat).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $device_id
 * @property string $command_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string $created_by
 * @property CarbonImmutable|null $client_created_at
 * @property CarbonImmutable $created_at
 */
class SyncCommand extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const string ACCEPTED = 'accepted';

    public const string REJECTED = 'rejected';

    public const string CONFLICT = 'conflict';

    public const string REQUIRES_REVIEW = 'requires_review';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'client_created_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Réponse renvoyée à l'appareil (identique à chaque rejeu).
     *
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return [
            'command_id' => $this->command_id,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->error_code !== null ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
        ];
    }
}
