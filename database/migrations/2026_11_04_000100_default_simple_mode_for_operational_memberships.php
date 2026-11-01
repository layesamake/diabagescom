<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Les rôles sans parcours comptable commencent dans le mode simple.
     *
     * Cette liste est dupliquée depuis TenantMembership afin que la migration
     * reste rejouable sans dépendre du code applicatif futur.
     *
     * @var list<string>
     */
    private const array SIMPLE_DEFAULT_ROLES = [
        'admin',
        'cashier',
        'warehouse_manager',
        'purchaser',
        'management',
    ];

    /**
     * Bascule les appartenances historiques encore sur l'ancien défaut expert,
     * mais préserve toute préférence explicitement enregistrée dans l'audit.
     */
    public function up(): void
    {
        DB::table('tenant_memberships')
            ->where('ui_mode', 'expert')
            ->whereIn('role_id', function ($query): void {
                $query->select('id')
                    ->from('roles')
                    ->whereNull('tenant_id')
                    ->where('scope', 'tenant')
                    ->where('system', true)
                    ->whereIn('code', self::SIMPLE_DEFAULT_ROLES);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_events')
                    ->whereRaw('audit_events.entity_id = CAST(tenant_memberships.id AS varchar)')
                    ->where('audit_events.entity_type', 'TenantMembership')
                    ->where('audit_events.action', 'membership.ui_mode_changed');
            })
            ->update([
                'ui_mode' => 'simple',
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    /**
     * Les préférences historiques ne peuvent pas être restaurées sans écraser
     * un choix utilisateur effectué après cette migration.
     */
    public function down(): void
    {
        // Intentionnellement sans effet.
    }
};
