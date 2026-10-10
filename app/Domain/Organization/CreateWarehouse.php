<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un dépôt dans l'entreprise courante, dans la limite de la formule.
 *
 * La souscription est verrouillée avant le comptage : deux créations
 * simultanées ne peuvent pas dépasser la limite.
 */
final class CreateWarehouse
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly SubscriptionLifecycle $subscriptions,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, address?: string|null}  $data
     */
    public function __invoke(array $data): Warehouse
    {
        $tenant = $this->context->tenant();

        return DB::transaction(function () use ($tenant, $data): Warehouse {
            $this->subscriptions->lockForTenant($tenant);
            $this->access->forget($tenant);
            $this->access->assertCanAdd($tenant, FeatureAccess::RESOURCE_WAREHOUSES);

            $warehouse = Warehouse::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
            ]);

            $this->audit->record('warehouse.created', $warehouse, after: $warehouse->only(['code', 'name']));

            return $warehouse;
        });
    }
}
