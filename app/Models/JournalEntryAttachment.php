<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pièce justificative d'une écriture comptable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entry_id
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $uploaded_by
 * @property CarbonImmutable|null $created_at
 */
class JournalEntryAttachment extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }
}
