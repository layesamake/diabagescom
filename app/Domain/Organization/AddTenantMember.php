<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ajoute un membre à l'entreprise courante dans la limite d'utilisateurs de la
 * formule (options comprises). Point d'entrée des futures invitations.
 */
final class AddTenantMember
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly SubscriptionLifecycle $subscriptions,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $user, string $roleCode): TenantMembership
    {
        if (! array_key_exists($roleCode, PermissionCatalog::tenantRoles())) {
            throw new InvalidArgumentException("Unknown tenant role [{$roleCode}].");
        }

        $tenant = $this->context->tenant();

        return DB::transaction(function () use ($tenant, $user, $roleCode): TenantMembership {
            $this->subscriptions->lockForTenant($tenant);
            $this->access->forget($tenant);
            $this->access->assertCanAdd($tenant, FeatureAccess::RESOURCE_USERS);

            $membership = new TenantMembership;
            $membership->tenant_id = $tenant->id;
            $membership->user_id = $user->id;
            $membership->role_id = Role::system(PermissionCatalog::SCOPE_TENANT, $roleCode)->id;
            $membership->status = TenantMembership::STATUS_ACTIVE;
            // Les rôles de terrain arrivent en mode simple ; les autres en mode expert.
            $membership->ui_mode = in_array($roleCode, TenantMembership::FIELD_ROLES, true)
                ? TenantMembership::MODE_SIMPLE
                : TenantMembership::MODE_EXPERT;
            $membership->save();

            $this->audit->record('membership.created', $membership, after: ['user_id' => $user->id, 'role' => $roleCode]);

            return $membership;
        });
    }
}
