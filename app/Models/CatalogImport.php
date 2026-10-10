<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Import CSV : aperçu validé ligne par ligne, puis application unique.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $status
 * @property string $file_name
 * @property string $file_hash
 * @property int $rows_total
 * @property int $rows_valid
 * @property list<array{line: int, messages: list<string>}>|null $errors
 * @property array<string, int>|null $summary
 * @property string $created_by
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 */
class CatalogImport extends Model
{
    use BelongsToTenant, HasUuids;

    public const string PREVIEWED = 'previewed';

    public const string APPLIED = 'applied';

    public const string DISCARDED = 'discarded';

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'rows_valid' => 'integer',
            'errors' => 'array',
            'summary' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
