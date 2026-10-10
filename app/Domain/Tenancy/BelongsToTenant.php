<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolation applicative d'un modèle appartenant à une entreprise.
 *
 * - Lecture : filtre automatique sur le tenant du contexte ; sans contexte,
 *   MissingTenantContext (échec fermé).
 * - Écriture : tenant_id imposé depuis le contexte ; une valeur différente est refusée.
 *
 * Les accès plateforme volontairement transverses utilisent
 * Model::withoutGlobalScope(TenantScope::class) de façon explicite et auditée.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $tenantId = $context->tenantId();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ($model->getAttribute('tenant_id') !== $tenantId) {
                throw new CrossTenantAccess('Cannot create a record for another tenant.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantAccess('tenant_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, Tenant $tenant): void
    {
        $query->withoutGlobalScope(TenantScope::class)->where($this->qualifyColumn('tenant_id'), $tenant->id);
    }
}
