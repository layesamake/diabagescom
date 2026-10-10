<?php

namespace App\Domain\Tenancy;

use App\Models\TenantMembership;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dépôts accessibles à un membre : tous les dépôts actifs pour l'administrateur,
 * sinon uniquement ceux qui lui sont explicitement affectés.
 */
final class WarehouseAccess
{
    /**
     * @return Builder<Warehouse>
     */
    public function query(TenantMembership $membership): Builder
    {
        $query = Warehouse::query()
            ->forTenant($membership->tenant)
            ->where('status', Warehouse::STATUS_ACTIVE)
            ->orderBy('name');

        if (! $membership->isAdmin()) {
            $query->whereIn('id', fn ($sub) => $sub->select('warehouse_id')
                ->from('warehouse_memberships')
                ->where('tenant_id', $membership->tenant_id)
                ->where('membership_id', $membership->id));
        }

        return $query;
    }

    /**
     * @return Collection<int, Warehouse>
     */
    public function accessible(TenantMembership $membership): Collection
    {
        return $this->query($membership)->get();
    }

    public function find(TenantMembership $membership, string $warehouseId): ?Warehouse
    {
        return $this->query($membership)->whereKey($warehouseId)->first();
    }
}
