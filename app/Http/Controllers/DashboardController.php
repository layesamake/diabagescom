<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil : raccourcis et alertes adaptés aux permissions.
 */
class DashboardController extends Controller
{
    public function __invoke(TenantContext $context, StockAccess $access): Response
    {
        $alerts = [];

        if (Gate::allows('inventory.view')) {
            // Articles sous leur seuil d'alerte, dans les dépôts accessibles.
            $alerts['low_stock'] = DB::table('product_warehouse_settings as s')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
                ->where('p.tenant_id', $context->tenantId())
                ->whereIn('s.warehouse_id', $access->accessibleIds())
                ->whereNull('p.archived_at')
                ->whereNotNull('s.reorder_point')
                ->whereRaw('COALESCE(b.quantity_on_hand, 0) <= s.reorder_point')
                ->count();

            $alerts['transfers_to_receive'] = DB::table('stock_transfers')
                ->where('tenant_id', $context->tenantId())
                ->where('status', 'in_transit')
                ->whereIn('destination_warehouse_id', $access->accessibleIds())
                ->count();
        }

        if (Gate::allows('sale.view')) {
            // Factures clients échues non soldées.
            $alerts['overdue_invoices'] = DB::table('sales_documents')
                ->where('tenant_id', $context->tenantId())
                ->whereIn('warehouse_id', $access->accessibleIds())
                ->where('type', 'invoice')->where('status', 'validated')
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->where('due_date', '<', now()->toDateString())
                ->count();
        }

        if (Gate::allows('cash.open_close')) {
            // Caisses restées ouvertes depuis un jour précédent : à fermer.
            $alerts['cash_to_close'] = DB::table('cash_sessions as s')
                ->join('cash_registers as r', 'r.id', '=', 's.register_id')
                ->where('s.tenant_id', $context->tenantId())
                ->whereIn('r.warehouse_id', $access->accessibleIds())
                ->where('s.status', 'open')
                ->where('s.opened_at', '<', now()->startOfDay())
                ->count();
        }

        return Inertia::render('Dashboard', [
            'alerts' => $alerts,
            'shortcuts' => [
                'sell' => Gate::allows('sale.create') && Gate::allows('sale.validate'),
                'quote' => Gate::allows('sale.create'),
                'cash' => Gate::any(['cash.view_own', 'cash.view_all']),
                'purchase' => Gate::allows('purchase.view'),
            ],
        ]);
    }
}
