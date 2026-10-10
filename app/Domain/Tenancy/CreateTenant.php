<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Crée une entreprise, son premier administrateur et son premier dépôt,
 * dans une seule transaction auditée.
 */
final class CreateTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{legal_name: string, display_name: string, country_code: string, timezone: string, warehouse_name: string, warehouse_code: string, warehouse_address?: string|null}  $data
     */
    public function __invoke(User $owner, array $data): TenantMembership
    {
        return DB::transaction(function () use ($owner, $data): TenantMembership {
            $tenant = new Tenant([
                'legal_name' => $data['legal_name'],
                'display_name' => $data['display_name'],
                'country_code' => $data['country_code'],
                'timezone' => $data['timezone'],
                'locale' => 'fr',
            ]);
            $tenant->created_by = $owner->id;
            $tenant->save();

            $membership = new TenantMembership;
            $membership->tenant_id = $tenant->id;
            $membership->user_id = $owner->id;
            $membership->role_id = Role::system(PermissionCatalog::SCOPE_TENANT, PermissionCatalog::ADMIN_ROLE)->id;
            $membership->status = TenantMembership::STATUS_ACTIVE;
            $membership->save();

            return $this->context->runAs($tenant, function () use ($tenant, $owner, $membership, $data): TenantMembership {
                $warehouse = Warehouse::query()->create([
                    'code' => $data['warehouse_code'],
                    'name' => $data['warehouse_name'],
                    'address' => $data['warehouse_address'] ?? null,
                ]);

                $this->audit->record('tenant.created', $tenant, after: $tenant->only(['legal_name', 'display_name', 'country_code', 'timezone']), actor: $owner, tenant: $tenant);
                $this->audit->record('membership.created', $membership, after: ['user_id' => $owner->id, 'role' => PermissionCatalog::ADMIN_ROLE], actor: $owner, tenant: $tenant);
                $this->audit->record('warehouse.created', $warehouse, after: $warehouse->only(['code', 'name']), actor: $owner, tenant: $tenant);

                return $membership;
            });
        });
    }
}
