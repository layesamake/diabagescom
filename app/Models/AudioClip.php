<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un morceau audio enregistré pour une phrase, dans la langue de la voix de
 * l'entreprise. Voir App\Domain\Audio\PhraseCatalog pour la liste des
 * phrases et le nombre de segments attendus par phrase, et
 * App\Domain\Audio\AudioLibrary pour la disponibilité d'une phrase.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $language
 * @property int $segment_index
 * @property string $file_path
 * @property int|null $duration_ms
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AudioClip extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['segment_index' => 'integer', 'duration_ms' => 'integer'];
    }
}
