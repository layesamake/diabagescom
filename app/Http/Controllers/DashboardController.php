<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAccess;
use App\Domain\Notifications\AlertCenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil : chiffres du jour, raccourcis et alertes adaptés aux permissions.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, StockAccess $access, AlertCenter $center, NotificationPreferences $preferences): Response
    {
        /** @var User $user */
        $user = $request->user();
        $pending = $center->pending($user);
        $visible = array_keys(array_filter($preferences->for($user, array_keys($pending)), fn (array $p) => $p['in_app']));
        $alerts = array_intersect_key($pending, array_flip($visible));

        return Inertia::render('Dashboard', [
            'figures' => $this->figures($context, $access),
            'checklist' => $this->checklist($context),
            'alerts' => $alerts,
            'shortcuts' => [
                'sell' => Gate::allows('sale.create') && Gate::allows('sale.validate'),
                'quote' => Gate::allows('sale.create'),
                'cash' => Gate::any(['cash.view_own', 'cash.view_all']),
                'purchase' => Gate::allows('purchase.view'),
            ],
        ]);
    }

    /**
     * Chiffres clés (mêmes règles que le rapport des ventes : factures validées
     * moins avoirs ; report.view_own limite à ses propres ventes).
     *
     * @return array<string, mixed>|null
     */
    private function figures(TenantContext $context, StockAccess $access): ?array
    {
        if (! Gate::any(['report.view_own', 'report.view_all'])) {
            return null;
        }

        $all = Gate::allows('report.view_all');
        $today = CarbonImmutable::today();
        $signed = "CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END";
        $documents = fn (CarbonImmutable $from): Builder => DB::table('sales_documents as d')
            ->where('d.tenant_id', $context->tenantId())
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->where('d.status', 'validated')
            ->where('d.validated_at', '>=', $from)
            ->whereIn('d.warehouse_id', $access->accessibleIds())
            ->when(! $all, fn (Builder $q) => $q->where('d.created_by', auth()->id()));

        $byDay = $documents($today->subDays(6))
            ->selectRaw("DATE(d.validated_at) AS day, SUM({$signed} * d.total) AS total, COUNT(*) FILTER (WHERE d.type = 'invoice') AS sales")
            ->groupByRaw('DATE(d.validated_at)')->get()->keyBy(fn ($r) => (string) $r->day);
        $week = collect(range(6, 0))->map(function (int $ago) use ($today, $byDay): array {
            $day = $today->subDays($ago)->toDateString();

            return ['day' => $day, 'total' => (int) ($byDay[$day]->total ?? 0), 'sales' => (int) ($byDay[$day]->sales ?? 0)];
        });

        return [
            'today' => $week->last(),
            'yesterday' => $week[5],
            'week' => $week->values(),
            'month' => (int) $documents($today->startOfMonth())->selectRaw("COALESCE(SUM({$signed} * d.total), 0) AS total")->value('total'),
            'receivables' => $all ? (int) DB::table('sales_documents')->where('tenant_id', $context->tenantId())
                ->where('type', 'invoice')->where('status', 'validated')->whereIn('warehouse_id', $access->accessibleIds())
                ->sum(DB::raw('GREATEST(total - amount_paid - amount_credited, 0)')) : null,
        ];
    }

    /**
     * Premiers pas d'une nouvelle entreprise (administrateur) : chaque étape
     * se coche d'elle-même dès qu'elle est réalisée. Null une fois tout fait.
     *
     * @return list<array{key: string, done: bool}>|null
     */
    private function checklist(TenantContext $context): ?array
    {
        if (! Gate::allows('organization.manage')) {
            return null;
        }

        $tenant = $context->tenantId();
        $exists = fn (string $table, ?callable $where = null): bool => DB::table($table)->where('tenant_id', $tenant)
            ->when($where !== null, fn (Builder $q) => $where($q))->exists();

        $steps = [
            ['key' => 'product', 'done' => $exists('products')],
            ['key' => 'stock', 'done' => $exists('inventory_movements')],
            ['key' => 'cash', 'done' => $exists('cash_sessions')],
            ['key' => 'sale', 'done' => $exists('sales_documents', fn (Builder $q) => $q->where('status', '<>', 'draft'))],
            ['key' => 'team', 'done' => DB::table('tenant_memberships')->where('tenant_id', $tenant)->count() > 1],
            ['key' => 'backup', 'done' => $exists('tenant_exports', fn (Builder $q) => $q->whereIn('status', ['ready', 'expired'])) || $exists('cloud_backup_connections')],
        ];

        return collect($steps)->every(fn (array $s) => $s['done']) ? null : $steps;
    }
}
