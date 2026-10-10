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

        return Inertia::render('Dashboard', ['alerts' => $alerts]);
    }
}
