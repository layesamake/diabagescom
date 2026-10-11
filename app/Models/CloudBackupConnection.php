<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stockage en ligne relié par l'entreprise pour y recevoir ses sauvegardes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string|null $account_email
 * @property string $refresh_token
 * @property string|null $folder_id
 * @property string $frequency
 * @property string $connected_by
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_status
 * @property string|null $last_error
 */
class CloudBackupConnection extends Model
{
    use BelongsToTenant, HasUuids;

    public const string GOOGLE_DRIVE = 'google_drive';

    /** @var list<string> */
    public const array FREQUENCIES = ['daily', 'weekly'];

    /** Nombre de sauvegardes conservées dans le dossier du client. */
    public const int KEEP = 10;

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            // Jeton chiffré avec la clé de l'application : illisible en base et dans les sauvegardes.
            'refresh_token' => 'encrypted',
            'last_run_at' => 'datetime',
        ];
    }

    public function isDue(): bool
    {
        return $this->last_run_at === null
            || $this->last_run_at->lte(now()->subHours($this->frequency === 'weekly' ? 24 * 7 - 1 : 23));
    }
}
