<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports de gestion (docs/02 §10) : chiffre d'affaires par période, dépôt,
 * vendeur et moyen de paiement, meilleurs articles, créances et dettes.
 * report.view_own : uniquement ses propres ventes ; report.view_all : tout ;
 * report.margin_view : coûts, marge et valorisation du stock.
 */
class SalesReportController extends Controller
{
    public function index(Request $request, TenantContext $context, StockAccess $access, AuditLog $audit): Response|StreamedResponse
    {
        abort_unless(Gate::any(['report.view_own', 'report.view_all']), 403);
        /** @var User $user */
        $user = $request->user();
        $all = Gate::allows('report.view_all');
        $margin = Gate::allows('report.margin_view');
        $warehouses = $access->accessibleIds();

        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->startOfMonth()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) $request->query('to', CarbonImmutable::today()->toDateString()))->endOfDay();
        $warehouse = in_array($request->query('warehouse'), $warehouses, true) ? (string) $request->query('warehouse') : null;

        // Ventes validées (factures) moins avoirs, sur le périmètre autorisé.
        $documents = fn (): Builder => DB::table('sales_documents as d')
            ->where('d.tenant_id', $context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->whereBetween('d.validated_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouse !== null ? [$warehouse] : $warehouses)
            ->when(! $all, fn (Builder $q) => $q->where('d.created_by', $user->id));
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        $totals = $documents()->selectRaw("COALESCE(SUM({$signed} * d.total), 0) AS total, COALESCE(SUM({$signed} * d.total_excl_tax), 0) AS excl,
            COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales")->first();

        $cost = $margin ? (int) $documents()->join('sales_document_lines as l', 'l.document_id', '=', 'd.id')
            ->selectRaw("COALESCE(SUM({$signed} * ROUND(l.quantity * COALESCE(l.unit_cost, 0))), 0) AS cost")
            ->whereRaw("(d.type = 'invoice' OR l.restock)")->value('cost') : null;

        $byDay = $documents()->selectRaw("DATE(d.validated_at) AS day, SUM({$signed} * d.total) AS total, COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales")
            ->groupByRaw('DATE(d.validated_at)')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'total' => (int) $r->total, 'sales' => (int) $r->sales]);

        $byWarehouse = $documents()->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->selectRaw("w.name, SUM({$signed} * d.total) AS total")->groupBy('w.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => (string) $r->name, 'total' => (int) $r->total]);

        $bySeller = $all ? $documents()->join('users as u', 'u.id', '=', 'd.created_by')
            ->selectRaw("u.name, SUM({$signed} * d.total) AS total")->groupBy('u.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => (string) $r->name, 'total' => (int) $r->total]) : collect();

        $byMethod = DB::table('payments as p')
            ->whereIn('p.sales_document_id', $documents()->select('d.id'))
            ->selectRaw("p.method, SUM(CASE WHEN p.direction = 'in' THEN p.amount ELSE -p.amount END) AS total")
            ->groupBy('p.method')->orderByDesc('total')->get()
            ->map(fn ($r) => ['method' => (string) $r->method, 'total' => (int) $r->total]);

        $topProducts = $documents()->join('sales_document_lines as l', 'l.document_id', '=', 'd.id')
            ->selectRaw("l.sku, l.label, SUM({$signed} * l.quantity) AS quantity, SUM({$signed} * l.line_total) AS total")
            ->groupBy('l.sku', 'l.label')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['sku' => (string) $r->sku, 'label' => (string) $r->label, 'quantity' => (string) $r->quantity, 'total' => (int) $r->total]);

        $receivables = $all ? (int) DB::table('sales_documents')->where('tenant_id', $context->tenantId())
            ->where('type', 'invoice')->where('status', 'validated')->whereIn('warehouse_id', $warehouses)
            ->sum(DB::raw('GREATEST(total - amount_paid - amount_credited, 0)')) : null;
        $payables = $all && Gate::allows('purchase.view') ? (int) DB::table('supplier_invoices')->where('tenant_id', $context->tenantId())
            ->where('status', 'validated')->whereIn('warehouse_id', $warehouses)
            ->sum(DB::raw('GREATEST(total - amount_paid - amount_credited, 0)')) : null;
        $stockValue = $margin ? (int) DB::table('inventory_balances')->where('tenant_id', $context->tenantId())
            ->whereIn('warehouse_id', $warehouse !== null ? [$warehouse] : $warehouses)
            ->where('quantity_on_hand', '>', 0)->sum(DB::raw('ROUND(quantity_on_hand * average_cost)')) : null;

        $total = (int) $totals?->total;
        $sales = (int) $totals?->sales;
        $summary = [
            'total' => $total,
            'excl_tax' => (int) $totals?->excl,
            'sales' => $sales,
            'average' => $sales > 0 ? intdiv($total, $sales) : 0,
            'cost' => $cost,
            'margin' => $cost !== null ? (int) $totals?->excl - $cost : null,
            'receivables' => $receivables,
            'payables' => $payables,
            'stock_value' => $stockValue,
        ];

        if ($request->query('format') === 'csv') {
            Gate::authorize('report.export');
            $audit->record('report.exported', metadata: ['report' => 'sales', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

            return response()->streamDownload(function () use ($byDay): void {
                $out = fopen('php://output', 'w');

                if ($out === false) {
                    return;
                }

                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Date', 'Ventes', 'Chiffre d’affaires TTC (XOF)'], ';', '"', '');

                foreach ($byDay as $row) {
                    fputcsv($out, [$row['day'], $row['sales'], $row['total']], ';', '"', '');
                }

                fclose($out);
            }, "ventes-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return Inertia::render('reports/Sales', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'warehouse' => $warehouse],
            'warehouses' => $access->accessible()->map->only(['id', 'name'])->values(),
            'summary' => $summary,
            'byDay' => $byDay,
            'byWarehouse' => $byWarehouse,
            'bySeller' => $bySeller,
            'byMethod' => $byMethod,
            'topProducts' => $topProducts,
            'can' => ['all' => $all, 'margin' => $margin, 'export' => Gate::allows('report.export')],
        ]);
    }
}
