<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\StockAccess;
use App\Domain\Reports\PilotageReport;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\TabularReport;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
    public function index(
        Request $request,
        TenantContext $context,
        StockAccess $access,
        AuditLog $audit,
        ReportExporter $exporter,
        PilotageReport $pilotage,
    ): Response|StreamedResponse|HttpResponse {
        abort_unless(Gate::any(['report.view_own', 'report.view_all']), 403);
        /** @var User $user */
        $user = $request->user();
        $all = Gate::allows('report.view_all');
        $margin = Gate::allows('report.margin_view');
        $warehouses = $access->accessibleIds();
        $timezone = $context->tenant()->timezone;
        $today = CarbonImmutable::now($timezone);
        $fromLocal = CarbonImmutable::parse((string) $request->query('from', $today->startOfMonth()->toDateString()), $timezone)->startOfDay();
        $toLocal = CarbonImmutable::parse((string) $request->query('to', $today->toDateString()), $timezone)->endOfDay();
        abort_if($fromLocal->greaterThan($toLocal), 422, __('reports.invalid_period'));
        $warehouse = in_array($request->query('warehouse'), $warehouses, true) ? (string) $request->query('warehouse') : null;
        $days = $fromLocal->startOfDay()->diffInDays($toLocal->startOfDay()) + 1;
        $previousToLocal = $fromLocal->subDay()->endOfDay();
        $previousFromLocal = $previousToLocal->startOfDay()->subDays($days - 1);
        $data = $pilotage->build(
            $user,
            $all,
            $margin,
            $warehouses,
            $warehouse,
            $fromLocal->utc(),
            $toLocal->utc(),
            $previousFromLocal->utc(),
            $previousToLocal->utc(),
            $timezone,
        );
        $summary = $data['summary'];
        $summary['receivables'] = $data['receivables']['total'] ?? null;
        $byDay = $data['byDay'];
        $byWarehouse = $data['byWarehouse'];
        $bySeller = $data['bySeller'];
        $byMethod = $data['byMethod'];
        $topProducts = $data['topProducts'];
        $from = $fromLocal;
        $to = $toLocal;

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

        if (in_array($request->query('format'), ReportExporter::FORMATS, true)) {
            Gate::authorize('report.export');
            $format = (string) $request->query('format');
            $audit->record('report.exported', metadata: ['report' => 'sales', 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

            $sections = [
                TabularReport::section(__('reports.summary'), [__('reports.indicator'), __('reports.value')], array_values(array_filter([
                    [__('reports.revenue'), $summary['total']],
                    [__('reports.excl_tax'), $summary['excl_tax']],
                    [__('reports.sales_count'), $summary['sales']],
                    [__('reports.average_basket'), $summary['average']],
                    $summary['cost'] !== null ? [__('reports.cost'), $summary['cost']] : null,
                    $summary['margin'] !== null ? [__('reports.margin'), $summary['margin']] : null,
                    $summary['receivables'] !== null ? [__('reports.receivables'), $summary['receivables']] : null,
                    $summary['payables'] !== null ? [__('reports.payables'), $summary['payables']] : null,
                    $summary['stock_value'] !== null ? [__('reports.stock_value'), $summary['stock_value']] : null,
                ]))),
                TabularReport::section(__('reports.by_day'), [__('reports.date'), __('reports.sales_count'), __('reports.revenue')],
                    $byDay->map(fn (array $r) => [$r['day'], $r['sales'], $r['total']])->all()),
                TabularReport::section(__('reports.by_warehouse'), [__('reports.warehouse'), __('reports.revenue')],
                    $byWarehouse->map(fn (array $r) => [$r['label'], $r['total']])->all()),
                TabularReport::section(__('reports.by_method'), [__('reports.method'), __('reports.revenue')],
                    $byMethod->map(fn (array $r) => [__('sales.methods.'.$r['method']), $r['total']])->all()),
                TabularReport::section(__('reports.top_products'), [__('reports.product'), __('reports.quantity'), __('reports.revenue')],
                    $topProducts->map(fn (array $r) => [$r['label'], $r['quantity'], $r['total']])->all()),
            ];

            if ($all) {
                $sections[] = TabularReport::section(__('reports.by_seller'), [__('reports.seller'), __('reports.revenue')],
                    $bySeller->map(fn (array $r) => [$r['label'], $r['total']])->all());
            }

            return $exporter->respond($format, new TabularReport(
                __('reports.title'),
                __('accounting.export.period', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]),
                $sections,
            ), "ventes-{$from->toDateString()}-{$to->toDateString()}");
        }

        return Inertia::render('reports/Sales', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'warehouse' => $warehouse],
            'period' => [
                'from' => $fromLocal->toDateString(),
                'to' => $toLocal->toDateString(),
                'previous_from' => $previousFromLocal->toDateString(),
                'previous_to' => $previousToLocal->toDateString(),
                'timezone' => $timezone,
            ],
            'warehouses' => $access->accessible()->map->only(['id', 'name'])->values(),
            'summary' => $summary,
            'comparison' => $data['comparison'],
            'byDay' => $byDay,
            'byWarehouse' => $byWarehouse,
            'bySeller' => $bySeller,
            'byMethod' => $byMethod,
            'topProducts' => $topProducts,
            'products' => $data['products'],
            'receivables' => $data['receivables'],
            'priorities' => $data['priorities'],
            'freshness' => now()->setTimezone($timezone)->toIso8601String(),
            'can' => ['all' => $all, 'margin' => $margin, 'export' => Gate::allows('report.export')],
        ]);
    }
}
