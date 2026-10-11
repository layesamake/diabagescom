<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Compte du plan comptable de l'entreprise (modèle SYSCOHADA personnalisable).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int $class
 * @property string|null $party_type
 * @property bool $reconcilable
 * @property bool $active
 */
class ChartAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name', 'class', 'party_type', 'reconcilable', 'active'];

    protected $attributes = ['reconcilable' => false, 'active' => true];

    protected function casts(): array
    {
        return ['class' => 'integer', 'reconcilable' => 'boolean', 'active' => 'boolean'];
    }
}
