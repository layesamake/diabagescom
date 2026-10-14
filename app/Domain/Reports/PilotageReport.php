<?php

namespace App\Domain\Reports;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model centralisé de l'espace Pilotage.
 *
 * Tous les montants restent en XOF entiers. Les ventes sont les factures
 * validées diminuées des avoirs validés. Les coûts utilisent exclusivement le
 * coût historique figé sur les lignes de vente.
 */
final class PilotageReport
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, mixed>
     */
    public function build(
        User $user,
        bool $all,
        bool $canSeeMargin,
        array $warehouseIds,
        ?string $warehouseId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
        string $timezone,
    ): array {
        $selectedWarehouses = $warehouseId !== null ? [$warehouseId] : $warehouseIds;
        $current = $this->metrics($user, $all, $canSeeMargin, $selectedWarehouses, $from, $to);
        $previous = $this->metrics($user, $all, $canSeeMargin, $selectedWarehouses, $previousFrom, $previousTo);
        $products = $this->products($user, $all, $canSeeMargin, $selectedWarehouses, $from, $to);
        $receivables = $all ? $this->receivables($selectedWarehouses, CarbonImmutable::now($timezone)->startOfDay()) : null;

        return [
            'summary' => $current,
            'comparison' => $this->comparison($current, $previous),
            'byDay' => $this->byDay($user, $all, $selectedWarehouses, $from, $to, $timezone),
            'byWarehouse' => $this->byWarehouse($user, $all, $selectedWarehouses, $from, $to),
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
            'priorities' => $this->priorities($current, $products, $receivables, $canSeeMargin),
        ];
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, int|null>
     */
    private function metrics(User $user, bool $all, bool $canSeeMargin, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): array
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
                COALESCE(SUM(CASE WHEN l.product_type <> 'service' AND l.unit_cost IS NULL THEN {$signed} * l.line_excl_tax ELSE 0 END), 0) AS missing_revenue")->first();
            $cost = (int) $costs?->cost;
            $missingCostRevenue = max(0, (int) $costs?->missing_revenue);
            $excl = (int) $totals?->excl;
            $costCoverageBasisPoints = $excl > 0
                ? max(0, min(10000, (int) round((($excl - $missingCostRevenue) * 10000) / $excl)))
                : null;
        }

        $stockValue = $canSeeMargin ? (int) DB::table('inventory_balances')
            ->where('tenant_id', $this->context->tenantId())
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity_on_hand', '>', 0)
            ->sum(DB::raw('ROUND(quantity_on_hand * average_cost)')) : null;

        $payables = $all ? (int) DB::table('supplier_invoices')
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
     * @return Collection<int, array<string, int|string|null>>
     */
    private function products(User $user, bool $all, bool $canSeeMargin, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
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

        return $rows->map(function ($row) use ($canSeeMargin): array {
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
                'cost_complete' => (int) $row->missing_cost_lines === 0,
                'stock' => $row->product_type === 'service' ? null : ($row->stock !== null ? (string) $row->stock : '0.000'),
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
     * @param  array<string, int|null>  $summary
     * @param  Collection<int, array<string, int|string|bool|null>>  $products
     * @param  array<string, mixed>|null  $receivables
     * @return list<array<string, int|string>>
     */
    private function priorities(array $summary, Collection $products, ?array $receivables, bool $canSeeMargin): array
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

        if ($canSeeMargin) {
            $negative = $products->filter(fn (array $product): bool => $product['margin'] !== null && $product['margin'] < 0);

            if ($negative->isNotEmpty()) {
                $priorities[] = [
                    'type' => 'negative_margin',
                    'level' => 'urgent',
                    'amount' => abs((int) $negative->sum('margin')),
                    'count' => $negative->count(),
                    'message' => 'Des articles ont une marge négative sur la période.',
                    'action' => 'Examiner les articles',
                ];
            }

            if (($summary['missing_cost_revenue'] ?? 0) > 0) {
                $priorities[] = [
                    'type' => 'missing_cost',
                    'level' => 'important',
                    'amount' => (int) $summary['missing_cost_revenue'],
                    'count' => $products->where('cost_complete', false)->count(),
                    'message' => 'Certains coûts historiques manquent : la marge est partielle.',
                    'action' => 'Voir les articles concernés',
                ];
            }
        }

        return array_slice($priorities, 0, 5);
    }

    /** @param list<string> $warehouseIds */
    private function documents(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): callable
    {
        return fn (): Builder => DB::table('sales_documents as d')
            ->where('d.tenant_id', $this->context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->whereBetween('d.validated_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->when(! $all, fn (Builder $query) => $query->where('d.created_by', $user->id));
    }

    /** @param list<string> $warehouseIds */
    private function byDay(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to, string $timezone): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return $this->documents($user, $all, $warehouseIds, $from, $to)()
            ->selectRaw("DATE(d.validated_at AT TIME ZONE ?) AS day, SUM({$signed} * d.total) AS total, COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales", [$timezone])
            ->groupByRaw('DATE(d.validated_at AT TIME ZONE ?)', [$timezone])
            ->orderBy('day')->get()
            ->map(fn ($row): array => ['day' => (string) $row->day, 'total' => (int) $row->total, 'sales' => (int) $row->sales]);
    }

    /** @param list<string> $warehouseIds */
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

    /** @param list<string> $warehouseIds */
    private function bySeller(array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";

        return DB::table('sales_documents as d')
            ->where('d.tenant_id', $this->context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->whereBetween('d.validated_at', [$from, $to])
            ->whereIn('d.warehouse_id', $warehouseIds)
            ->join('users as u', 'u.id', '=', 'd.created_by')
            ->selectRaw("u.name, SUM({$signed} * d.total) AS total")
            ->groupBy('u.id', 'u.name')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['label' => (string) $row->name, 'total' => (int) $row->total]);
    }

    /** @param list<string> $warehouseIds */
    private function byMethod(User $user, bool $all, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $documents = $this->documents($user, $all, $warehouseIds, $from, $to);

        return DB::table('payments as p')
            ->where('p.tenant_id', $this->context->tenantId())
            ->whereIn('p.sales_document_id', $documents()->select('d.id'))
            ->selectRaw("p.method, SUM(CASE WHEN p.direction = 'in' THEN p.amount ELSE -p.amount END) AS total")
            ->groupBy('p.method')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['method' => (string) $row->method, 'total' => (int) $row->total]);
    }
}
