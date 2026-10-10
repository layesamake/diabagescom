<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WarehouseAccess;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Un utilisateur n'agit que sur les dépôts qui lui sont affectés (docs/11 :
 * « un utilisateur non affecté à un dépôt ne peut pas voir ni modifier son stock »).
 * L'administrateur accède à tous les dépôts actifs.
 */
final class StockAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WarehouseAccess $warehouses,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function warehouse(string $warehouseId): Warehouse
    {
        $membership = $this->context->membership() ?? throw new AuthorizationException;

        return $this->warehouses->find($membership, $warehouseId)
            ?? throw new AuthorizationException(__('inventory.errors.warehouse_forbidden'));
    }

    /**
     * @return Collection<int, Warehouse>
     */
    public function accessible(): Collection
    {
        $membership = $this->context->membership();

        return $membership === null ? collect() : $this->warehouses->accessible($membership);
    }

    /**
     * @return list<string>
     */
    public function accessibleIds(): array
    {
        return array_values($this->accessible()->map(fn (Warehouse $w): string => $w->id)->all());
    }
}
