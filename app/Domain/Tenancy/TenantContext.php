<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Context;

/**
 * Contexte d'entreprise et de dépôt de la requête (ou du job) en cours.
 *
 * Il n'est renseigné que par le serveur (middleware ResolveTenantContext ou
 * code applicatif explicite) et jamais à partir d'un champ HTTP. Les modèles
 * BelongsToTenant refusent toute requête tant qu'il est vide.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    private ?Warehouse $warehouse = null;

    public function set(Tenant $tenant, ?TenantMembership $membership = null, ?Warehouse $warehouse = null): void
    {
        if ($membership !== null && $membership->tenant_id !== $tenant->id) {
            throw new CrossTenantAccess('Membership does not belong to the tenant.');
        }

        if ($warehouse !== null && $warehouse->tenant_id !== $tenant->id) {
            throw new CrossTenantAccess('Warehouse does not belong to the tenant.');
        }

        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->warehouse = $warehouse;

        Context::add('tenant_id', $tenant->id);
    }

    public function clear(): void
    {
        $this->tenant = $this->membership = $this->warehouse = null;
        Context::forget('tenant_id');
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function tenantId(): string
    {
        return $this->tenant()->id;
    }

    public function tenantOrNull(): ?Tenant
    {
        return $this->tenant;
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function warehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    /**
     * Exécute $callback dans le contexte d'un tenant puis restaure le précédent.
     * Réservé aux jobs, commandes et services plateforme explicites.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        [$tenantBefore, $membershipBefore, $warehouseBefore] = [$this->tenant, $this->membership, $this->warehouse];

        try {
            $this->set($tenant);

            return $callback();
        } finally {
            if ($tenantBefore !== null) {
                $this->set($tenantBefore, $membershipBefore, $warehouseBefore);
            } else {
                $this->clear();
            }
        }
    }
}
