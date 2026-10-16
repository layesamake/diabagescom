<?php

namespace App\Domain\Reports;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read model centralisé de l'espace Pilotage.
 *
 * Tous les montants restent en XOF entiers. Les ventes sont les factures
 * validées diminuées des avoirs validés. Les coûts utilisent exclusivement le
 * coût historique figé sur les lignes de vente.
 */
final class PilotageReport
{
    /** Fenêtre d'observation de la demande, en jours. */
    private const int COVERAGE_WINDOW_DAYS = 90;

    /** Jours d'historique minimum (depuis la première entrée en stock) pour calculer une couverture. */
    private const int COVERAGE_MIN_HISTORY_DAYS = 14;

    /** Nombre minimum de ventes (factures) sur la fenêtre pour calculer une couverture. */
    private const int COVERAGE_MIN_SALES = 3;

    /** Jours de demande que doit couvrir un dépôt après transfert (et que la source conserve). */
    private const int TRANSFER_TARGET_DAYS = 30;

    /** Un article est « à risque » sous ce nombre de jours de couverture. */
    private const int COVERAGE_RISK_DAYS = 7;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, mixed>
     */
    public function build(
        User $user,
        bool $all,
        bool $canSeeMargin,
        bool $canSeeInventory,
        bool $canSeePurchases,
        array $warehouseIds,
        ?string $warehouseId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
        string $timezone,
        int $dormantDays = 90,
    ): array {
        $selectedWarehouses = $warehouseId !== null ? [$warehouseId] : $warehouseIds;
        $current = $this->metrics($user, $all, $canSeeMargin, $canSeeInventory, $canSeePurchases, $selectedWarehouses, $from, $to);
        $previous = $this->metrics($user, $all, $canSeeMargin, $canSeeInventory, $canSeePurchases, $selectedWarehouses, $previousFrom, $previousTo);
        $products = $this->products($user, $all, $canSeeMargin, $canSeeInventory, $selectedWarehouses, $from, $to);
        $priorityMetrics = $canSeeMargin ? $this->productPriorityMetrics($user, $all, $selectedWarehouses, $from, $to) : null;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $receivables = $all ? $this->receivables($selectedWarehouses, $today) : null;
        $payablesSchedule = $all && $canSeePurchases ? $this->payablesSchedule($selectedWarehouses, $today) : null;
        // Une demande calculée sur ses seules ventes serait faussée : vue d'ensemble réservée à report.view_all.
        $stockInsights = $all && $canSeeInventory
            ? [
                'dormant' => $this->dormantStock($selectedWarehouses, $today, $dormantDays, $canSeeMargin, $timezone),
                'coverage' => $this->stockCoverage($selectedWarehouses, $today),
                'transfers' => $this->transferSuggestions($selectedWarehouses, $today),
            ]
            : null;

        return [
            'summary' => $current,
            'comparison' => $this->comparison($current, $previous),
            'byDay' => $this->byDay($user, $all, $selectedWarehouses, $from, $to, $timezone),
            'byDayMargin' => $canSeeMargin ? $this->byDayMargin($user, $all, $selectedWarehouses, $from, $to, $timezone) : collect(),
            'byWarehouse' => $this->byWarehouse($user, $all, $selectedWarehouses, $from, $to),
            'warehouseComparison' => $this->warehouseComparison($user, $all, $canSeeMargin, $canSeeInventory, $canSeePurchases, $selectedWarehouses, $from, $to),
            'bySeller' => $all ? $this->bySeller($selectedWarehouses, $from, $to) : collect(),
            'byMethod' => $this->byMethod($user, $all, $selectedWarehouses, $from, $to),
            'products' => $products,
            'topProducts' => $products->take(10)->map(fn (array $row): array => [
                'sku' => $row['sku'],
                'label' => $row['label'],
                'quantity' => $row['quantity'],
                'total' => $row['revenue'],
            ]),
            'receivables' => $receivables,
            'payablesSchedule' => $payablesSchedule,
            'stockInsights' => $stockInsights,
            'priorities' => $this->priorities($current, $priorityMetrics, $receivables, $payablesSchedule, $stockInsights, $canSeeMargin),
        ];
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, int|null>
     */
    private function metrics(User $user, bool $all, bool $canSeeMargin, bool $canSeeInventory, bool $canSeePurchases, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $documents = $this->documents($user, $all, $warehouseIds, $from, $to);
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";
        $totals = $documents()->selectRaw("COALESCE(SUM({$signed} * d.total), 0) AS total, COALESCE(SUM({$signed} * d.total_excl_tax), 0) AS excl, COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales")->first();

        $cost = null;
        $missingCostRevenue = null;
        $costCoverageBasisPoints = null;

        if ($canSeeMargin) {
            $costs = $documents()->join('sales_document_lines as l', function ($join): void {
                $join->on('l.document_id', '=', 'd.id')->on('l.tenant_id', '=', 'd.tenant_id');
            })->selectRaw("COALESCE(SUM(CASE
                    WHEN l.product_type = 'service' THEN 0
                    WHEN l.unit_cost IS NULL THEN 0
                    WHEN d.type = 'invoice' THEN ROUND(l.quantity * l.unit_cost)
                    WHEN d.type = 'credit_note' AND l.restock THEN -ROUND(l.quantity * l.unit_cost)
                    ELSE 0 END), 0) AS cost,
                COALESCE(SUM(ABS(l.line_excl_tax)) FILTER (WHERE l.product_type <> 'service' AND l.unit_cost IS NULL), 0) AS missing_revenue,
                COALESCE(SUM(ABS(l.line_excl_tax)) FILTER (WHERE l.product_type <> 'service'), 0) AS eligible_revenue")->first();
            $cost = (int) $costs?->cost;
            $missingCostRevenue = (int) $costs?->missing_revenue;
            $eligibleRevenue = (int) $costs?->eligible_revenue;
            $costCoverageBasisPoints = $eligibleRevenue > 0
                ? max(0, min(10000, (int) round((($eligibleRevenue - $missingCostRevenue) * 10000) / $eligibleRevenue)))
                : null;
        }

        $stockValue = $canSeeMargin && $canSeeInventory ? (int) DB::table('inventory_balances')
            ->where('tenant_id', $this->context->tenantId())
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity_on_hand', '>', 0)
            ->sum(DB::raw('ROUND(quantity_on_hand * average_cost)')) : null;

        $payables = $all && $canSeePurchases ? (int) DB::table('supplier_invoices')
            ->where('tenant_id', $this->context->tenantId())
            ->where('status', 'validated')
            ->whereIn('warehouse_id', $warehouseIds)
            ->sum(DB::raw('GREATEST(total - amount_paid - amount_credited, 0)')) : null;

        $total = (int) $totals?->total;
        $excl = (int) $totals?->excl;
        $sales = (int) $totals?->sales;
        $margin = $cost !== null ? $excl - $cost : null;

        return [
            'total' => $total,
            'excl_tax' => $excl,
            'sales' => $sales,
            'average' => $sales > 0 ? intdiv($total, $sales) : 0,
            'cost' => $cost,
            'margin' => $margin,
            'margin_rate_basis_points' => $margin !== null && $excl > 0 ? (int) round(($margin * 10000) / $excl) : null,
            'cost_coverage_basis_points' => $costCoverageBasisPoints,
            'missing_cost_revenue' => $missingCostRevenue,
            'receivables' => null,
            'payables' => $payables,
            'stock_value' => $stockValue,
        ];
    }

    /**
     * @param  array<string, int|null>  $current
     * @param  array<string, int|null>  $previous
     * @return array<string, array{value: int|null, change: int|null, change_basis_points: int|null}>
     */
    private function comparison(array $current, array $previous): array
    {
        $result = [];

        foreach (['total', 'excl_tax', 'sales', 'average', 'margin'] as $key) {
            $value = $previous[$key];
            $currentValue = $current[$key];
            $change = $value !== null && $currentValue !== null ? $currentValue - $value : null;
            $result[$key] = [
                'value' => $value,
                'change' => $change,
                'change_basis_points' => $change !== null && $value !== 0
                    ? (int) round(($change * 10000) / abs($value))
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array<string, bool|int|string|null>>
     */
    private function products(User $user, bool $all, bool $canSeeMargin, bool $canSeeInventory, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $stock = DB::table('inventory_balances')
            ->where('tenant_id', $this->context->tenantId())
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('product_id, SUM(quantity_on_hand - quantity_reserved) AS available')
            ->groupBy('product_id');
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";
        $rows = $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->join('sales_document_lines as l', function ($join): void {
                $join->on('l.document_id', '=', 'd.id')->on('l.tenant_id', '=', 'd.tenant_id');
            })
            ->leftJoinSub($stock, 's', 's.product_id', '=', 'l.product_id')
            ->selectRaw("l.product_id, l.sku, l.label, l.unit_code, l.product_type,
                SUM({$signed} * l.quantity) AS quantity,
                SUM({$signed} * l.line_total) AS revenue,
                SUM({$signed} * l.line_excl_tax) AS revenue_excl_tax,
                MAX(d.validated_at) FILTER (WHERE d.type = 'invoice') AS last_sale,
                MAX(s.available) AS stock,
                COALESCE(SUM(CASE
                    WHEN l.product_type = 'service' THEN 0
                    WHEN l.unit_cost IS NULL THEN 0
                    WHEN d.type = 'invoice' THEN ROUND(l.quantity * l.unit_cost)
                    WHEN d.type = 'credit_note' AND l.restock THEN -ROUND(l.quantity * l.unit_cost)
                    ELSE 0 END), 0) AS cost,
                COUNT(*) FILTER (WHERE l.product_type <> 'service' AND l.unit_cost IS NULL) AS missing_cost_lines")
            ->groupBy('l.product_id', 'l.sku', 'l.label', 'l.unit_code', 'l.product_type')
            ->orderByDesc($canSeeMargin ? 'revenue_excl_tax' : 'revenue')
            ->limit(25)
            ->get();

        return $rows->map(function ($row) use ($canSeeMargin, $canSeeInventory): array {
            $revenueExclTax = (int) $row->revenue_excl_tax;
            $cost = (int) $row->cost;
            $margin = $canSeeMargin ? $revenueExclTax - $cost : null;

            return [
                'id' => (string) $row->product_id,
                'sku' => (string) $row->sku,
                'label' => (string) $row->label,
                'unit' => (string) $row->unit_code,
                'type' => (string) $row->product_type,
                'quantity' => (string) $row->quantity,
                'revenue' => (int) $row->revenue,
                'revenue_excl_tax' => $revenueExclTax,
                'cost' => $canSeeMargin ? $cost : null,
                'margin' => $margin,
                'margin_rate_basis_points' => $margin !== null && $revenueExclTax > 0 ? (int) round(($margin * 10000) / $revenueExclTax) : null,
                'cost_complete' => $canSeeMargin ? (int) $row->missing_cost_lines === 0 : null,
                'stock' => $canSeeInventory && $row->product_type !== 'service'
                    ? ($row->stock !== null ? (string) $row->stock : '0.000')
                    : null,
                'last_sale' => $row->last_sale !== null ? CarbonImmutable::parse((string) $row->last_sale)->toIso8601String() : null,
            ];
        });
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, mixed>
     */
    private function receivables(array $warehouseIds, CarbonImmutable $today): array
    {
        $balance = 'GREATEST(d.total - d.amount_paid - d.amount_credited, 0)';
        $date = $today->toDateString();
        $summary = DB::table('sales_documents as d')
            ->where('d.tenant_id', $this->context->tenantId())
            ->where('d.type', 'invoice')
            ->where('d.status', 'validated')
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->whereRaw("{$balance} > 0")
            ->selectRaw("COALESCE(SUM({$balance}), 0) AS total,
                COALESCE(SUM({$balance}) FILTER (WHERE d.due_date < ?), 0) AS overdue,
                COALESCE(SUM({$balance}) FILTER (WHERE d.due_date IS NULL), 0) AS missing_due", [$date])
            ->first();
        $rows = DB::table('sales_documents as d')
            ->where('d.tenant_id', $this->context->tenantId())
            ->where('d.type', 'invoice')
            ->where('d.status', 'validated')
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->whereRaw("{$balance} > 0")
            ->whereNotNull('d.due_date')
            ->where('d.due_date', '<', $date)
            ->selectRaw("CASE
                    WHEN ?::date - d.due_date BETWEEN 1 AND 7 THEN '1_7'
                    WHEN ?::date - d.due_date BETWEEN 8 AND 30 THEN '8_30'
                    WHEN ?::date - d.due_date BETWEEN 31 AND 60 THEN '31_60'
                    ELSE 'over_60' END AS bucket,
                SUM({$balance}) AS amount, COUNT(*) AS count", [$date, $date, $date])
            ->groupBy('bucket')
            ->get()->keyBy('bucket');

        $aging = [];

        foreach (['1_7', '8_30', '31_60', 'over_60'] as $bucket) {
            $aging[$bucket] = [
                'amount' => (int) ($rows[$bucket]->amount ?? 0),
                'count' => (int) ($rows[$bucket]->count ?? 0),
            ];
        }

        return [
            'total' => (int) $summary?->total,
            'overdue' => (int) $summary?->overdue,
            'missing_due' => (int) $summary?->missing_due,
            'aging' => $aging,
        ];
    }

    /**
     * Couverture de stock et risque de rupture, par article sur le périmètre.
     *
     * Demande/jour = (ventes − quantités remises en stock par avoir) sur la fenêtre, divisées
     * par les jours effectifs (min(fenêtre, ancienneté de la première entrée en stock)).
     * Couverture = stock disponible ÷ demande/jour, arrondie à l'entier inférieur.
     * Elle n'est calculée qu'avec assez d'historique (jours et ventes) ; sinon l'article est
     * « Historique insuffisant », et sans aucune vente « Aucune vente » : jamais de
     * couverture rassurante inventée. Rupture et seuil de réapprovisionnement sont des
     * faits : ils s'affichent même sans historique. Les commandes d'achat attendues ne sont
     * pas déduites, et une demande observée pendant une rupture peut être sous-estimée.
     *
     * @param  list<string>  $warehouseIds
     * @return array<string, mixed>
     */
    private function stockCoverage(array $warehouseIds, CarbonImmutable $today): array
    {
        $parameters = [
            'window_days' => self::COVERAGE_WINDOW_DAYS,
            'min_history_days' => self::COVERAGE_MIN_HISTORY_DAYS,
            'min_sales' => self::COVERAGE_MIN_SALES,
            'risk_days' => self::COVERAGE_RISK_DAYS,
        ];
        $counts = ['at_risk' => 0, 'out_of_stock' => 0, 'insufficient_history' => 0, 'no_sales' => 0];

        if ($warehouseIds === []) {
            return [...$parameters, 'counts' => $counts, 'items' => []];
        }

        $tenant = $this->context->tenantId();
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $windowStart = $today->subDays(self::COVERAGE_WINDOW_DAYS)->toIso8601String();

        $rows = DB::select("WITH sb AS (
                SELECT b.product_id, SUM(b.quantity_on_hand - b.quantity_reserved) AS available
                FROM inventory_balances b
                WHERE b.tenant_id = ? AND b.warehouse_id IN ({$in})
                GROUP BY b.product_id
            ), ro AS (
                SELECT s.product_id,
                    BOOL_OR(s.reorder_point IS NOT NULL AND COALESCE(b.quantity_on_hand, 0) <= s.reorder_point) AS below,
                    MAX(COALESCE(s.lead_time_days, 7)) AS lead_time_days
                FROM product_warehouse_settings s
                LEFT JOIN inventory_balances b ON b.tenant_id = s.tenant_id AND b.product_id = s.product_id AND b.warehouse_id = s.warehouse_id
                WHERE s.tenant_id = ? AND s.warehouse_id IN ({$in})
                GROUP BY s.product_id
            ), sa AS (
                SELECT l.product_id,
                    SUM(CASE WHEN d.type = 'invoice' THEN l.quantity WHEN l.restock THEN -l.quantity ELSE 0 END) AS sold,
                    COUNT(DISTINCT d.id) FILTER (WHERE d.type = 'invoice') AS sales_count,
                    COUNT(DISTINCT l.unit_code) AS units
                FROM sales_documents d
                JOIN sales_document_lines l ON l.document_id = d.id AND l.tenant_id = d.tenant_id
                WHERE d.tenant_id = ? AND d.status = 'validated' AND d.type IN ('invoice', 'credit_note')
                    AND d.warehouse_id IN ({$in}) AND d.validated_at >= ?::timestamptz
                GROUP BY l.product_id
            ), fi AS (
                SELECT m.product_id, MIN(m.occurred_at) AS first_in
                FROM inventory_movements m
                WHERE m.tenant_id = ? AND m.warehouse_id IN ({$in}) AND m.quantity_delta > 0
                GROUP BY m.product_id
            )
            SELECT p.id, p.sku, p.name, u.code AS unit, sb.available,
                COALESCE(ro.below, false) AS below_reorder,
                COALESCE(ro.lead_time_days, 7) AS lead_time_days,
                COALESCE(sa.sold, 0) AS sold, COALESCE(sa.sales_count, 0) AS sales_count, COALESCE(sa.units, 0) AS units,
                FLOOR(EXTRACT(EPOCH FROM (?::timestamptz - fi.first_in)) / 86400) AS age_days
            FROM sb
            JOIN products p ON p.id = sb.product_id AND p.tenant_id = ?
            JOIN units u ON u.id = p.unit_id
            LEFT JOIN ro ON ro.product_id = sb.product_id
            LEFT JOIN sa ON sa.product_id = sb.product_id
            LEFT JOIN fi ON fi.product_id = sb.product_id
            WHERE p.type IN ('stockable', 'consumable') AND p.archived_at IS NULL",
            [$tenant, ...$warehouseIds, $tenant, ...$warehouseIds, $tenant, ...$warehouseIds, $windowStart,
                $tenant, ...$warehouseIds, $today->toIso8601String(), $tenant]);

        $milli = fn (mixed $value): int => (int) round(((float) $value) * 1000);
        $format = fn (int $milli): string => number_format($milli / 1000, 3, '.', '');
        $items = [];

        foreach ($rows as $row) {
            $available = $milli($row->available);
            $sold = $milli($row->sold);
            $salesCount = (int) $row->sales_count;
            $effectiveDays = min(self::COVERAGE_WINDOW_DAYS, max(0, (int) $row->age_days));

            if ((int) $row->units > 1 || $effectiveDays < self::COVERAGE_MIN_HISTORY_DAYS) {
                $state = 'insufficient_history';
            } elseif ($salesCount === 0 || $sold <= 0) {
                $state = 'no_sales';
            } elseif ($salesCount < self::COVERAGE_MIN_SALES) {
                $state = 'insufficient_history';
            } else {
                $state = 'ok';
            }

            $coverageDays = $state === 'ok' && $available > 0 ? intdiv($available * $effectiveDays, $sold) : null;
            $below = (bool) $row->below_reorder;
            $leadTimeDays = max(1, (int) $row->lead_time_days);
            $status = null;

            if ($available <= 0 && $salesCount > 0) {
                $status = 'out';
                $coverageDays = 0;
            } elseif ($state === 'ok' && $coverageDays !== null && $coverageDays < $leadTimeDays) {
                $status = 'low';
            } elseif ($below) {
                $status = 'reorder';
            }

            if ($available > 0 && $state === 'insufficient_history') {
                $counts['insufficient_history']++;
            }

            if ($available > 0 && $state === 'no_sales') {
                $counts['no_sales']++;
            }

            if ($status === null) {
                continue;
            }

            $items[] = [
                'id' => (string) $row->id,
                'sku' => (string) $row->sku,
                'label' => (string) $row->name,
                'unit' => (string) $row->unit,
                'available' => $format($available),
                'sold' => $format($sold),
                'daily_demand' => $state === 'ok' ? $format((int) round($sold / $effectiveDays)) : null,
                'sales_count' => $salesCount,
                'coverage_days' => $coverageDays,
                'lead_time_days' => $leadTimeDays,
                'reorder_date' => $status !== null
                    ? $today->addDays(max(0, ($coverageDays ?? 0) - $leadTimeDays))->toDateString()
                    : null,
                'coverage_state' => $state,
                'status' => $status,
                'below_reorder' => $below,
            ];
        }

        $rank = ['out' => 0, 'low' => 1, 'reorder' => 2];
        usort($items, fn (array $a, array $b): int => [$rank[$a['status']], $a['coverage_days'] ?? PHP_INT_MAX, $a['sku']]
            <=> [$rank[$b['status']], $b['coverage_days'] ?? PHP_INT_MAX, $b['sku']]);

        $counts['at_risk'] = count($items);
        $counts['out_of_stock'] = count(array_filter($items, fn (array $i): bool => $i['status'] === 'out'));

        return [...$parameters, 'counts' => $counts, 'items' => array_slice($items, 0, 20)];
    }

    /**
     * Transferts suggérés : un dépôt dont la couverture est sous le seuil (demande fiable)
     * reçoit l'excédent d'un autre dépôt du périmètre. Besoin = demande/jour × 30 − disponible.
     * La source conserve sa propre demande × 30 (ou son seuil de réapprovisionnement s'il est
     * plus haut) ; sans historique fiable (moins de 14 jours, ou quelques ventes seulement),
     * elle n'est jamais vidée. Quantité = min(excédent, besoin), arrondie à l'unité si l'article
     * n'accepte pas les décimales. Simple suggestion : aucun transfert n'est créé.
     *
     * @param  list<string>  $warehouseIds
     * @return array<string, mixed>
     */
    private function transferSuggestions(array $warehouseIds, CarbonImmutable $today): array
    {
        $result = ['target_days' => self::TRANSFER_TARGET_DAYS, 'items' => []];

        if (count($warehouseIds) < 2) {
            return $result;
        }

        $tenant = $this->context->tenantId();
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $windowStart = $today->subDays(self::COVERAGE_WINDOW_DAYS)->toIso8601String();

        $rows = DB::select("WITH sb AS (
                SELECT b.warehouse_id, b.product_id, b.quantity_on_hand - b.quantity_reserved AS available
                FROM inventory_balances b WHERE b.tenant_id = ? AND b.warehouse_id IN ({$in})
            ), sa AS (
                SELECT d.warehouse_id, l.product_id,
                    SUM(CASE WHEN d.type = 'invoice' THEN l.quantity WHEN l.restock THEN -l.quantity ELSE 0 END) AS sold,
                    COUNT(DISTINCT d.id) FILTER (WHERE d.type = 'invoice') AS sales_count,
                    COUNT(DISTINCT l.unit_code) AS units
                FROM sales_documents d
                JOIN sales_document_lines l ON l.document_id = d.id AND l.tenant_id = d.tenant_id
                WHERE d.tenant_id = ? AND d.status = 'validated' AND d.type IN ('invoice', 'credit_note')
                    AND d.warehouse_id IN ({$in}) AND d.validated_at >= ?::timestamptz
                GROUP BY d.warehouse_id, l.product_id
            ), fi AS (
                SELECT m.warehouse_id, m.product_id, MIN(m.occurred_at) AS first_in
                FROM inventory_movements m
                WHERE m.tenant_id = ? AND m.warehouse_id IN ({$in}) AND m.quantity_delta > 0
                GROUP BY m.warehouse_id, m.product_id
            )
            SELECT sb.warehouse_id, p.id AS product_id, p.sku, p.name, u.code AS unit, u.decimals_allowed,
                sb.available, s.reorder_point,
                COALESCE(sa.sold, 0) AS sold, COALESCE(sa.sales_count, 0) AS sales_count, COALESCE(sa.units, 0) AS units,
                FLOOR(EXTRACT(EPOCH FROM (?::timestamptz - fi.first_in)) / 86400) AS age_days
            FROM sb
            JOIN products p ON p.id = sb.product_id AND p.tenant_id = ?
            JOIN units u ON u.id = p.unit_id
            LEFT JOIN product_warehouse_settings s ON s.tenant_id = p.tenant_id AND s.product_id = sb.product_id AND s.warehouse_id = sb.warehouse_id
            LEFT JOIN sa ON sa.warehouse_id = sb.warehouse_id AND sa.product_id = sb.product_id
            LEFT JOIN fi ON fi.warehouse_id = sb.warehouse_id AND fi.product_id = sb.product_id
            WHERE p.type IN ('stockable', 'consumable') AND p.archived_at IS NULL",
            [$tenant, ...$warehouseIds, $tenant, ...$warehouseIds, $windowStart, $tenant, ...$warehouseIds,
                $today->toIso8601String(), $tenant]);

        $milli = fn (mixed $value): int => (int) round(((float) $value) * 1000);
        $byProduct = [];

        foreach ($rows as $row) {
            $available = $milli($row->available);
            $sold = $milli($row->sold);
            $sales = (int) $row->sales_count;
            $age = max(0, (int) $row->age_days);
            $effective = min(self::COVERAGE_WINDOW_DAYS, $age);
            $reliable = (int) $row->units <= 1 && $effective >= self::COVERAGE_MIN_HISTORY_DAYS
                && $sales >= self::COVERAGE_MIN_SALES && $sold > 0;
            $idle = $sales === 0 && $age >= self::COVERAGE_MIN_HISTORY_DAYS;
            $target = $reliable ? intdiv($sold * self::TRANSFER_TARGET_DAYS, $effective) : 0;
            $reorder = $row->reorder_point !== null ? $milli($row->reorder_point) : 0;

            $byProduct[(string) $row->product_id][] = [
                'warehouse' => (string) $row->warehouse_id,
                'sku' => (string) $row->sku,
                'label' => (string) $row->name,
                'unit' => (string) $row->unit,
                'whole' => ! (bool) $row->decimals_allowed,
                'available' => $available,
                'coverage' => $reliable ? intdiv(max(0, $available) * $effective, $sold) : null,
                'need' => $reliable ? max(0, $target - max(0, $available)) : 0,
                'surplus' => ($reliable || $idle) ? max(0, $available - max($target, $reorder)) : 0,
            ];
        }

        $names = DB::table('warehouses')->where('tenant_id', $tenant)->whereIn('id', $warehouseIds)->pluck('name', 'id');
        $format = fn (int $milli): string => number_format($milli / 1000, 3, '.', '');
        $items = [];

        foreach ($byProduct as $productId => $stocks) {
            $destinations = array_filter($stocks, fn (array $s): bool => $s['coverage'] !== null
                && $s['coverage'] < self::COVERAGE_RISK_DAYS && $s['need'] > 0);
            usort($destinations, fn (array $a, array $b): int => $a['coverage'] <=> $b['coverage']);

            foreach ($destinations as $destination) {
                $sources = array_filter($stocks, fn (array $s): bool => $s['warehouse'] !== $destination['warehouse'] && $s['surplus'] > 0);
                usort($sources, fn (array $a, array $b): int => $b['surplus'] <=> $a['surplus']);
                $source = $sources[0] ?? null;

                if ($source === null) {
                    continue;
                }

                $quantity = min($source['surplus'], $destination['need']);
                $quantity = $destination['whole'] ? intdiv($quantity, 1000) * 1000 : $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                foreach ($stocks as $i => $s) {
                    if ($s['warehouse'] === $source['warehouse']) {
                        $stocks[$i]['surplus'] -= $quantity;
                    }
                }

                $items[] = [
                    'product_id' => $productId,
                    'sku' => $destination['sku'],
                    'label' => $destination['label'],
                    'unit' => $destination['unit'],
                    'source' => ['id' => $source['warehouse'], 'name' => (string) ($names[$source['warehouse']] ?? '')],
                    'destination' => ['id' => $destination['warehouse'], 'name' => (string) ($names[$destination['warehouse']] ?? '')],
                    'quantity' => $format($quantity),
                    'source_available' => $format($source['available']),
                    'destination_available' => $format($destination['available']),
                    'destination_coverage_days' => $destination['coverage'],
                ];
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['destination_coverage_days'], $a['sku']] <=> [$b['destination_coverage_days'], $b['sku']]);
        $result['items'] = array_slice($items, 0, 20);

        return $result;
    }

    /**
     * Stock dormant : article en stock (quantité positive, hors services et archivés),
     * sans vente depuis $days jours et en stock depuis au moins $days jours, pour ne pas
     * qualifier de dormant un article récemment réceptionné. La valeur immobilisée
     * (quantité × coût moyen) n'est calculée qu'avec report.margin_view ; la dernière
     * vente est cherchée sans borne de date, sur les dépôts du périmètre.
     *
     * @param  list<string>  $warehouseIds
     * @return array{days: int, count: int, value: int|null, items: list<array<string, mixed>>}
     */
    private function dormantStock(array $warehouseIds, CarbonImmutable $today, int $days, bool $canSeeMargin, string $timezone): array
    {
        $empty = ['days' => $days, 'count' => 0, 'value' => $canSeeMargin ? 0 : null, 'items' => []];

        if ($warehouseIds === []) {
            return $empty;
        }

        $tenant = $this->context->tenantId();
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $cutoff = $today->subDays($days)->toIso8601String();
        $order = $canSeeMargin ? 'x.value DESC, x.sku' : 'x.quantity DESC, x.sku';

        $rows = DB::select("SELECT * FROM (
                SELECT p.id, p.sku, p.name, u.code AS unit,
                    SUM(b.quantity_on_hand) AS quantity,
                    SUM(ROUND(b.quantity_on_hand * b.average_cost)) AS value,
                    (SELECT MAX(d.validated_at) FROM sales_documents d
                        JOIN sales_document_lines l ON l.document_id = d.id AND l.tenant_id = d.tenant_id
                        WHERE d.tenant_id = ? AND d.type = 'invoice' AND d.status = 'validated'
                            AND d.warehouse_id IN ({$in}) AND l.product_id = p.id) AS last_sale,
                    (SELECT MIN(m.occurred_at) FROM inventory_movements m
                        WHERE m.tenant_id = ? AND m.product_id = p.id
                            AND m.warehouse_id IN ({$in}) AND m.quantity_delta > 0) AS first_in
                FROM inventory_balances b
                JOIN products p ON p.id = b.product_id AND p.tenant_id = b.tenant_id
                JOIN units u ON u.id = p.unit_id
                WHERE b.tenant_id = ? AND b.warehouse_id IN ({$in}) AND b.quantity_on_hand > 0
                    AND p.type IN ('stockable', 'consumable') AND p.archived_at IS NULL
                GROUP BY p.id, p.sku, p.name, u.code
            ) x
            WHERE x.first_in IS NOT NULL AND x.first_in <= ?::timestamptz
                AND (x.last_sale IS NULL OR x.last_sale < ?::timestamptz)
            ORDER BY {$order}",
            [$tenant, ...$warehouseIds, $tenant, ...$warehouseIds, $tenant, ...$warehouseIds, $cutoff, $cutoff]);

        $items = array_map(fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'sku' => (string) $row->sku,
            'label' => (string) $row->name,
            'unit' => (string) $row->unit,
            'quantity' => (string) $row->quantity,
            'last_sale' => $row->last_sale !== null ? CarbonImmutable::parse((string) $row->last_sale)->setTimezone($timezone)->toDateString() : null,
            'value' => $canSeeMargin ? (int) $row->value : null,
        ], $rows);

        return [
            'days' => $days,
            'count' => count($items),
            'value' => $canSeeMargin ? array_sum(array_column($items, 'value')) : null,
            'items' => array_values(array_slice($items, 0, 20)),
        ];
    }

    /**
     * Échéancier des dettes fournisseurs : solde restant (total − réglé − avoirs) des
     * factures validées, par date d'échéance. Les factures sans échéance sont isolées,
     * jamais rangées dans une tranche inventée. Les tranches s'additionnent au total.
     *
     * @param  list<string>  $warehouseIds
     * @return array{total: int, overdue: array{amount: int, count: int}, due_7: array{amount: int, count: int}, due_8_30: array{amount: int, count: int}, later: array{amount: int, count: int}, missing_due: array{amount: int, count: int}}
     */
    private function payablesSchedule(array $warehouseIds, CarbonImmutable $today): array
    {
        $balance = 'GREATEST(i.total - i.amount_paid - i.amount_credited, 0)';
        $date = $today->toDateString();
        $rows = DB::table('supplier_invoices as i')
            ->where('i.tenant_id', $this->context->tenantId())
            ->where('i.status', 'validated')
            ->whereIn('i.warehouse_id', $warehouseIds)
            ->whereRaw("{$balance} > 0")
            ->selectRaw("CASE
                    WHEN i.due_date IS NULL THEN 'missing_due'
                    WHEN i.due_date < ?::date THEN 'overdue'
                    WHEN i.due_date <= ?::date + 7 THEN 'due_7'
                    WHEN i.due_date <= ?::date + 30 THEN 'due_8_30'
                    ELSE 'later' END AS bucket,
                SUM({$balance}) AS amount, COUNT(*) AS count", [$date, $date, $date])
            ->groupBy('bucket')
            ->get()->keyBy('bucket');

        $schedule = ['total' => 0];

        foreach (['overdue', 'due_7', 'due_8_30', 'later', 'missing_due'] as $bucket) {
            $schedule[$bucket] = [
                'amount' => (int) ($rows[$bucket]->amount ?? 0),
                'count' => (int) ($rows[$bucket]->count ?? 0),
            ];
            $schedule['total'] += $schedule[$bucket]['amount'];
        }

        return $schedule;
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return array{negative_amount: int, negative_count: int, missing_count: int}
     */
    private function productPriorityMetrics(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";
        $perProduct = $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->join('sales_document_lines as l', function ($join): void {
                $join->on('l.document_id', '=', 'd.id')->on('l.tenant_id', '=', 'd.tenant_id');
            })
            ->selectRaw("l.product_id,
                SUM({$signed} * l.line_excl_tax) - COALESCE(SUM(CASE
                    WHEN l.product_type = 'service' THEN 0
                    WHEN l.unit_cost IS NULL THEN 0
                    WHEN d.type = 'invoice' THEN ROUND(l.quantity * l.unit_cost)
                    WHEN d.type = 'credit_note' AND l.restock THEN -ROUND(l.quantity * l.unit_cost)
                    ELSE 0 END), 0) AS margin,
                COUNT(*) FILTER (WHERE l.product_type <> 'service' AND l.unit_cost IS NULL) AS missing_cost_lines")
            ->groupBy('l.product_id');
        $row = DB::query()->fromSub($perProduct, 'product_metrics')
            ->selectRaw('COALESCE(SUM(ABS(margin)) FILTER (WHERE margin < 0), 0) AS negative_amount,
                COUNT(*) FILTER (WHERE margin < 0) AS negative_count,
                COUNT(*) FILTER (WHERE missing_cost_lines > 0) AS missing_count')
            ->first();

        return [
            'negative_amount' => (int) $row?->negative_amount,
            'negative_count' => (int) $row?->negative_count,
            'missing_count' => (int) $row?->missing_count,
        ];
    }

    /**
     * @param  array<string, int|null>  $summary
     * @param  array{negative_amount: int, negative_count: int, missing_count: int}|null  $productMetrics
     * @param  array<string, mixed>|null  $receivables
     * @param  array<string, mixed>|null  $payablesSchedule
     * @param  array<string, mixed>|null  $stockInsights
     * @return list<array<string, int|string>>
     */
    private function priorities(array $summary, ?array $productMetrics, ?array $receivables, ?array $payablesSchedule, ?array $stockInsights, bool $canSeeMargin): array
    {
        $priorities = [];

        if (($receivables['overdue'] ?? 0) > 0) {
            $priorities[] = [
                'type' => 'receivables',
                'level' => ($receivables['aging']['over_60']['amount'] ?? 0) > 0 ? 'urgent' : 'important',
                'amount' => (int) $receivables['overdue'],
                'count' => array_sum(array_column($receivables['aging'], 'count')),
                'message' => 'Des factures clients sont arrivées à échéance.',
                'action' => 'Voir les factures',
            ];
        }

        if (($stockInsights['coverage']['counts']['at_risk'] ?? 0) > 0) {
            $priorities[] = [
                'type' => 'stock_risk',
                'level' => $stockInsights['coverage']['counts']['out_of_stock'] > 0 ? 'urgent' : 'important',
                'amount' => 0,
                'count' => (int) $stockInsights['coverage']['counts']['at_risk'],
                'message' => 'Des articles risquent la rupture de stock.',
                'action' => 'Voir le stock',
            ];
        }

        if (($payablesSchedule['overdue']['amount'] ?? 0) > 0) {
            $priorities[] = [
                'type' => 'payables_overdue',
                'level' => 'important',
                'amount' => (int) $payablesSchedule['overdue']['amount'],
                'count' => (int) $payablesSchedule['overdue']['count'],
                'message' => 'Des factures fournisseurs sont arrivées à échéance.',
                'action' => 'Voir les factures fournisseurs',
            ];
        }

        if ($canSeeMargin && $productMetrics !== null) {
            if ($productMetrics['negative_count'] > 0) {
                $priorities[] = [
                    'type' => 'negative_margin',
                    'level' => 'urgent',
                    'amount' => $productMetrics['negative_amount'],
                    'count' => $productMetrics['negative_count'],
                    'message' => 'Des articles ont une marge négative sur la période.',
                    'action' => 'Examiner les articles',
                ];
            }

            if (($summary['missing_cost_revenue'] ?? 0) > 0) {
                $priorities[] = [
                    'type' => 'missing_cost',
                    'level' => 'important',
                    'amount' => (int) $summary['missing_cost_revenue'],
                    'count' => $productMetrics['missing_count'],
                    'message' => 'Certains coûts historiques manquent : la marge est partielle.',
                    'action' => 'Voir les articles concernés',
                ];
            }
        }

        // Une priorité sans montant connu n'a pas d'impact chiffrable : seulement avec la valeur du stock.
        if ($canSeeMargin && ($stockInsights['dormant']['count'] ?? 0) > 0) {
            $priorities[] = [
                'type' => 'dormant_stock',
                'level' => 'watch',
                'amount' => (int) $stockInsights['dormant']['value'],
                'count' => (int) $stockInsights['dormant']['count'],
                'message' => "Du stock n'a pas été vendu depuis {$stockInsights['dormant']['days']} jours.",
                'action' => 'Voir le stock',
            ];
        }

        return array_slice($priorities, 0, 5);
    }

    /** @param list<string> $warehouseIds */
    private function documents(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): callable
    {
        return fn (): Builder => DB::table('sales_documents as d')
            ->leftJoin('sales_documents as source', function ($join): void {
                $join->on('source.id', '=', 'd.source_document_id')->on('source.tenant_id', '=', 'd.tenant_id');
            })
            ->where('d.tenant_id', $this->context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->whereBetween('d.validated_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->when(! $all, fn (Builder $query) => $query->where(function (Builder $scope) use ($user): void {
                $scope->where(function (Builder $invoices) use ($user): void {
                    $invoices->where('d.type', 'invoice')->where('d.created_by', $user->id);
                })->orWhere(function (Builder $credits) use ($user): void {
                    $credits->where('d.type', 'credit_note')->where('source.created_by', $user->id);
                });
            }));
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array{day: string, total: int, sales: int}>
     */
    private function byDay(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to, string $timezone): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->selectRaw("DATE(d.validated_at AT TIME ZONE ?) AS day, SUM({$signed} * d.total) AS total, COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales", [$timezone])
            ->groupBy('day')
            ->orderBy('day')->get()
            ->map(fn ($row): array => ['day' => (string) $row->day, 'total' => (int) $row->total, 'sales' => (int) $row->sales]);
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array{day: string, margin: int}>
     */
    private function byDayMargin(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to, string $timezone): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->join('sales_document_lines as l', function ($join): void {
                $join->on('l.document_id', '=', 'd.id')->on('l.tenant_id', '=', 'd.tenant_id');
            })
            ->selectRaw("DATE(d.validated_at AT TIME ZONE ?) AS day,
                SUM({$signed} * l.line_excl_tax) - COALESCE(SUM(CASE
                    WHEN l.product_type = 'service' THEN 0
                    WHEN l.unit_cost IS NULL THEN 0
                    WHEN d.type = 'invoice' THEN ROUND(l.quantity * l.unit_cost)
                    WHEN d.type = 'credit_note' AND l.restock THEN -ROUND(l.quantity * l.unit_cost)
                    ELSE 0 END), 0) AS margin", [$timezone])
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($row): array => ['day' => (string) $row->day, 'margin' => (int) $row->margin]);
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array{label: string, total: int}>
     */
    private function byWarehouse(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->join('warehouses as w', function ($join): void {
                $join->on('w.id', '=', 'd.warehouse_id')->on('w.tenant_id', '=', 'd.tenant_id');
            })
            ->selectRaw("w.name, SUM({$signed} * d.total) AS total")
            ->groupBy('w.id', 'w.name')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['label' => (string) $row->name, 'total' => (int) $row->total]);
    }

    /**
     * Comparaison des dépôts du périmètre : mêmes définitions que les totaux (ventes nettes
     * des avoirs, marge sur coût historique figé, stock valorisé au coût moyen, dettes
     * fournisseurs ouvertes). Aucune comparaison avec moins de deux dépôts. Coûts, valeur
     * du stock et dettes sont nuls sans leur droit ; les ventes suivent le périmètre
     * (vendeur ou global) comme le reste de l'écran.
     *
     * @param  list<string>  $warehouseIds
     * @return list<array<string, mixed>>
     */
    private function warehouseComparison(User $user, bool $all, bool $canSeeMargin, bool $canSeeInventory, bool $canSeePurchases, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (count($warehouseIds) < 2) {
            return [];
        }

        $tenant = $this->context->tenantId();
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";
        $documents = $this->documents($user, $all, $warehouseIds, $from, $to);

        $sales = $documents()
            ->selectRaw("d.warehouse_id, COALESCE(SUM({$signed} * d.total), 0) AS total, COALESCE(SUM({$signed} * d.total_excl_tax), 0) AS excl,
                COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales")
            ->groupBy('d.warehouse_id')->get()->keyBy('warehouse_id');

        $costs = $canSeeMargin ? $documents()
            ->join('sales_document_lines as l', function ($join): void {
                $join->on('l.document_id', '=', 'd.id')->on('l.tenant_id', '=', 'd.tenant_id');
            })
            ->selectRaw("d.warehouse_id, COALESCE(SUM(CASE
                    WHEN l.product_type = 'service' THEN 0
                    WHEN l.unit_cost IS NULL THEN 0
                    WHEN d.type = 'invoice' THEN ROUND(l.quantity * l.unit_cost)
                    WHEN d.type = 'credit_note' AND l.restock THEN -ROUND(l.quantity * l.unit_cost)
                    ELSE 0 END), 0) AS cost,
                COUNT(*) FILTER (WHERE l.product_type <> 'service' AND l.unit_cost IS NULL) AS missing")
            ->groupBy('d.warehouse_id')->get()->keyBy('warehouse_id') : collect();

        $stock = $canSeeMargin && $canSeeInventory ? DB::table('inventory_balances')
            ->where('tenant_id', $tenant)->whereIn('warehouse_id', $warehouseIds)->where('quantity_on_hand', '>', 0)
            ->selectRaw('warehouse_id, SUM(ROUND(quantity_on_hand * average_cost)) AS value')
            ->groupBy('warehouse_id')->pluck('value', 'warehouse_id') : collect();

        $payables = $all && $canSeePurchases ? DB::table('supplier_invoices')
            ->where('tenant_id', $tenant)->where('status', 'validated')->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, SUM(GREATEST(total - amount_paid - amount_credited, 0)) AS due')
            ->groupBy('warehouse_id')->pluck('due', 'warehouse_id') : collect();

        $rows = [];

        foreach (DB::table('warehouses')->where('tenant_id', $tenant)->whereIn('id', $warehouseIds)->get(['id', 'name']) as $warehouse) {
            $id = (string) $warehouse->id;
            $excl = (int) ($sales[$id]->excl ?? 0);
            $margin = $canSeeMargin ? $excl - (int) ($costs[$id]->cost ?? 0) : null;

            $rows[] = [
                'id' => $id,
                'name' => (string) $warehouse->name,
                'total' => (int) ($sales[$id]->total ?? 0),
                'excl_tax' => $excl,
                'sales' => (int) ($sales[$id]->sales ?? 0),
                'margin' => $margin,
                'margin_rate_basis_points' => $margin !== null && $excl > 0 ? (int) round(($margin * 10000) / $excl) : null,
                'cost_complete' => $canSeeMargin ? (int) ($costs[$id]->missing ?? 0) === 0 : null,
                'stock_value' => $canSeeMargin && $canSeeInventory ? (int) ($stock[$id] ?? 0) : null,
                'payables' => $all && $canSeePurchases ? (int) ($payables[$id] ?? 0) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array{label: string, total: int}>
     */
    private function bySeller(array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return DB::table('sales_documents as d')
            ->leftJoin('sales_documents as source', function ($join): void {
                $join->on('source.id', '=', 'd.source_document_id')->on('source.tenant_id', '=', 'd.tenant_id');
            })
            ->where('d.tenant_id', $this->context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->whereBetween('d.validated_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->join('users as u', function ($join): void {
                $join->on('u.id', '=', DB::raw('COALESCE(source.created_by, d.created_by)'));
            })
            ->selectRaw("u.name, SUM({$signed} * d.total) AS total")
            ->groupBy('u.id', 'u.name')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['label' => (string) $row->name, 'total' => (int) $row->total]);
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Collection<int, array{method: string, total: int}>
     */
    private function byMethod(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('payments as p')
            ->join('sales_documents as d', function ($join): void {
                $join->on('d.id', '=', 'p.sales_document_id')->on('d.tenant_id', '=', 'p.tenant_id');
            })
            ->leftJoin('sales_documents as source', function ($join): void {
                $join->on('source.id', '=', 'd.source_document_id')->on('source.tenant_id', '=', 'd.tenant_id');
            })
            ->where('p.tenant_id', $this->context->tenantId())
            ->whereBetween('p.paid_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->where('d.status', 'validated')
            ->when(! $all, fn (Builder $query) => $query->where(function (Builder $scope) use ($user): void {
                $scope->where(function (Builder $invoices) use ($user): void {
                    $invoices->where('d.type', 'invoice')->where('d.created_by', $user->id);
                })->orWhere(function (Builder $credits) use ($user): void {
                    $credits->where('d.type', 'credit_note')->where('source.created_by', $user->id);
                });
            }))
            ->selectRaw("p.method, SUM(CASE WHEN p.direction = 'in' THEN p.amount ELSE -p.amount END) AS total")
            ->groupBy('p.method')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['method' => (string) $row->method, 'total' => (int) $row->total]);
    }
}
