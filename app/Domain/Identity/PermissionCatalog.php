<?php

namespace App\Domain\Identity;

/**
 * Catalogue central des permissions et des rôles intégrés (docs/13-permissions.md).
 *
 * Source de vérité unique : la base est synchronisée depuis cette classe
 * (commande diaba:sync-permissions), le Gate s'y réfère et le frontend en reçoit
 * le sous-ensemble accordé. Le rôle "admin" d'un tenant n'a aucune ligne en base :
 * "toutes les permissions tenant" est résolu dans l'application.
 */
final class PermissionCatalog
{
    public const string SCOPE_TENANT = 'tenant';

    public const string SCOPE_PLATFORM = 'platform';

    public const string ADMIN_ROLE = 'admin';

    public const string SUPER_ADMIN_ROLE = 'super_admin';

    /** @var array<string, list<string>> module => actions (portée tenant) */
    private const array TENANT = [
        'organization' => ['view', 'manage'],
        'warehouse' => ['view', 'manage'],
        'member' => ['view', 'invite', 'manage'],
        'role' => ['view', 'manage'],
        'audit' => ['view'],
        'catalog' => ['view', 'create', 'update', 'archive', 'price_override'],
        'customer' => ['view', 'manage'],
        'supplier' => ['view', 'manage'],
        'sale' => ['view', 'create', 'update_draft', 'validate', 'cancel', 'discount_standard', 'discount_exception', 'sell_negative_stock'],
        'purchase' => ['view', 'create', 'update_draft', 'receive', 'validate', 'cancel'],
        'purchase_request' => ['view', 'create', 'approve'],
        'inventory' => ['view', 'transfer_create', 'transfer_ship', 'transfer_receive', 'count_create', 'count_validate', 'adjust'],
        'cash' => ['view_own', 'view_all', 'open_close', 'receive', 'expense', 'refund'],
        'bank' => ['view', 'reconcile'],
        'accounting' => ['view', 'manual_entry', 'post', 'period_close', 'settings', 'export'],
        'report' => ['view_own', 'view_all', 'margin_view', 'export'],
    ];

    /** @var list<string> */
    private const array PLATFORM = [
        'platform.tenants.manage',
        'platform.plans.manage',
        'platform.billing.manage',
        'platform.support_access',
        'platform.audit.view',
    ];

    /**
     * Rôles tenant intégrés, hors administrateur (résolu applicativement).
     * Les mentions « délégation » de la matrice ne sont pas accordées par défaut.
     *
     * @var array<string, array{name: string, permissions: list<string>}>
     */
    private const array TENANT_ROLES = [
        'cashier' => [
            'name' => 'Vendeur / caissier',
            'permissions' => [
                'organization.view', 'warehouse.view', 'catalog.view', 'customer.view', 'customer.manage',
                'sale.view', 'sale.create', 'sale.update_draft', 'sale.validate', 'sale.discount_standard',
                'cash.view_own', 'cash.open_close', 'cash.receive', 'report.view_own',
                'purchase_request.view', 'purchase_request.create',
            ],
        ],
        'warehouse_manager' => [
            'name' => 'Gestionnaire de dépôt',
            'permissions' => [
                'organization.view', 'warehouse.view', 'catalog.view', 'supplier.view', 'sale.view',
                'purchase.view', 'purchase.receive', 'purchase_request.view', 'purchase_request.create',
                'inventory.view', 'inventory.transfer_create', 'inventory.transfer_ship', 'inventory.transfer_receive',
                'inventory.count_create', 'inventory.count_validate', 'inventory.adjust',
                'report.view_own', 'report.export',
            ],
        ],
        'purchaser' => [
            'name' => 'Responsable achats',
            'permissions' => [
                'organization.view', 'warehouse.view', 'catalog.view', 'supplier.view', 'supplier.manage',
                'purchase.view', 'purchase.create', 'purchase.update_draft', 'purchase.receive', 'purchase.validate', 'purchase.cancel',
                'purchase_request.view', 'purchase_request.create', 'purchase_request.approve',
                'inventory.view', 'accounting.view', 'report.view_own', 'report.export',
            ],
        ],
        'accountant' => [
            'name' => 'Comptable',
            'permissions' => [
                'organization.view', 'warehouse.view', 'catalog.view', 'customer.view', 'supplier.view',
                'sale.view', 'purchase.view', 'inventory.view', 'cash.view_all', 'bank.view', 'bank.reconcile',
                'accounting.view', 'accounting.manual_entry', 'accounting.post', 'accounting.period_close',
                'accounting.settings', 'accounting.export',
                'report.view_all', 'report.margin_view', 'report.export',
            ],
        ],
        'management' => [
            'name' => 'Direction',
            'permissions' => [
                'organization.view', 'warehouse.view', 'catalog.view', 'customer.view', 'supplier.view',
                'sale.view', 'purchase.view', 'inventory.view', 'cash.view_all', 'bank.view', 'accounting.view',
                'report.view_all', 'report.margin_view', 'report.export',
                'purchase_request.view', 'purchase_request.approve',
            ],
        ],
    ];

    /**
     * @return array<string, array{scope: string, module: string, action: string}>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::TENANT as $module => $actions) {
            foreach ($actions as $action) {
                $permissions["{$module}.{$action}"] = ['scope' => self::SCOPE_TENANT, 'module' => $module, 'action' => $action];
            }
        }

        foreach (self::PLATFORM as $code) {
            [$module, $action] = explode('.', $code, 2);
            $permissions[$code] = ['scope' => self::SCOPE_PLATFORM, 'module' => $module, 'action' => $action];
        }

        return $permissions;
    }

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    public static function scopeOf(string $code): ?string
    {
        return self::all()[$code]['scope'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function tenantPermissions(): array
    {
        return array_keys(array_filter(self::all(), fn (array $p) => $p['scope'] === self::SCOPE_TENANT));
    }

    /**
     * @return list<string>
     */
    public static function platformPermissions(): array
    {
        return self::PLATFORM;
    }

    /**
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    public static function tenantRoles(): array
    {
        return [
            self::ADMIN_ROLE => ['name' => 'Administrateur', 'permissions' => []],
            ...self::TENANT_ROLES,
        ];
    }

    /**
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    public static function platformRoles(): array
    {
        return [
            self::SUPER_ADMIN_ROLE => ['name' => 'Super-administrateur DIABA', 'permissions' => self::PLATFORM],
        ];
    }
}
