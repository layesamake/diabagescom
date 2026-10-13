<?php

namespace App\Domain\Notifications;

use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Points à traiter d'un utilisateur dans l'entreprise courante (stock faible,
 * factures échues, caisses à fermer…). Chaque catégorie n'est calculée que si
 * l'utilisateur en a le droit, et seulement sur ses dépôts accessibles.
 *
 * Même source pour l'accueil et pour le résumé quotidien par e-mail.
 */
final class AlertCenter
{
    public const array CATEGORIES = [
        'low_stock', 'transfers_to_receive', 'overdue_invoices', 'cash_to_close', 'sync_issues', 'saas_invoice',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StockAccess $access,
    ) {}

    /**
     * Valeur de chaque catégorie autorisée : nombre, ou numéro de facture
     * (saas_invoice, null si rien à régler).
     *
     * @return array<string, int|string|null>
     */
    public function pending(User $user): array
    {
        $gate = Gate::forUser($user);
        $tenantId = $this->context->tenantId();
        $warehouses = $this->access->accessibleIds();
        $alerts = [];

        if ($gate->allows('inventory.view')) {
            $alerts['low_stock'] = DB::table('product_warehouse_settings as s')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
                ->where('p.tenant_id', $tenantId)
                ->whereIn('s.warehouse_id', $warehouses)
                ->whereNull('p.archived_at')
                ->whereNotNull('s.reorder_point')
                ->whereRaw('COALESCE(b.quantity_on_hand, 0) <= s.reorder_point')
                ->count();

            $alerts['transfers_to_receive'] = DB::table('stock_transfers')
                ->where('tenant_id', $tenantId)
                ->where('status', 'in_transit')
                ->whereIn('destination_warehouse_id', $warehouses)
                ->count();
        }

        if ($gate->allows('sale.view')) {
            $alerts['overdue_invoices'] = DB::table('sales_documents')
                ->where('tenant_id', $tenantId)
                ->whereIn('warehouse_id', $warehouses)
                ->where('type', 'invoice')->where('status', 'validated')
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->where('due_date', '<', now()->toDateString())
                ->count();
        }

        if ($gate->allows('cash.open_close')) {
            $alerts['cash_to_close'] = DB::table('cash_sessions as s')
                ->join('cash_registers as r', 'r.id', '=', 's.register_id')
                ->where('s.tenant_id', $tenantId)
                ->whereIn('r.warehouse_id', $warehouses)
                ->where('s.status', 'open')
                ->where('s.opened_at', '<', now()->startOfDay())
                ->count();
        }

        if ($gate->allows('organization.manage')) {
            $alerts['saas_invoice'] = DB::table('saas_invoices')->where('tenant_id', $tenantId)
                ->where('status', 'open')->value('number');
        }

        if ($gate->allows('sale.view')) {
            $alerts['sync_issues'] = DB::table('sync_commands')->where('tenant_id', $tenantId)
                ->where('created_by', $user->id)
                ->whereIn('status', ['rejected', 'conflict', 'requires_review'])
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
        }

        return $alerts;
    }

    /**
     * Catégories que l'utilisateur peut recevoir (selon ses droits).
     *
     * @return list<string>
     */
    public function available(User $user): array
    {
        return array_keys($this->pending($user));
    }

    /**
     * Ce qu'il reste à traiter, sans les catégories vides.
     *
     * @param  array<string, int|string|null>  $pending
     * @return array<string, int>
     */
    public static function counts(array $pending): array
    {
        $counts = [];

        foreach ($pending as $category => $value) {
            $n = is_int($value) ? $value : ($value !== null && $value !== '' ? 1 : 0);

            if ($n > 0) {
                $counts[$category] = $n;
            }
        }

        return $counts;
    }
}
