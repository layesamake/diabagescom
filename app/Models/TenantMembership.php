<?php

namespace App\Models;

use App\Domain\Identity\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Appartenance d'un utilisateur à une entreprise, avec un rôle.
 *
 * Pas de scope tenant automatique : la résolution du contexte doit lister les
 * appartenances d'un utilisateur. Toute requête filtre explicitement par
 * user_id ou tenant_id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property string $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User $user
 * @property-read Role $role
 */
class TenantMembership extends Model
{
    use HasUuids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    protected $attributes = ['status' => 'active'];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Dépôts explicitement affectés (l'administrateur accède à tous les dépôts).
     *
     * @return BelongsToMany<Warehouse, $this>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_memberships', 'membership_id', 'warehouse_id')
            ->withPivot('tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->role->system
            && $this->role->tenant_id === null
            && $this->role->code === PermissionCatalog::ADMIN_ROLE;
    }
}
