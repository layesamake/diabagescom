<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $scope
 * @property string $code
 * @property string $name
 * @property bool $system
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'scope', 'code', 'name', 'system'];

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_code');
    }

    /**
     * Rôle intégré global (partagé par tous les tenants), identifié par son code.
     */
    public static function system(string $scope, string $code): self
    {
        return self::query()
            ->whereNull('tenant_id')
            ->where(['scope' => $scope, 'code' => $code, 'system' => true])
            ->firstOrFail();
    }
}
