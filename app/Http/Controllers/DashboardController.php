<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAccess;
use App\Domain\Notifications\AlertCenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Onboarding\StartGuide;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil : chiffres du jour, actions du rôle et alertes adaptés aux permissions.
 */
class DashboardController extends Controller
{
    /**
     * Actions rapides de l'accueil : celles que le rôle utilise le plus, dans
     * l'ordre de son métier, limitées à ce que ses permissions autorisent.
     */
    private const array ACTION_ORDER = [
        'admin' => ['sell', 'quote', 'cash', 'purchase', 'product', 'stock', 'reports', 'accounting'],
        'cashier' => ['sell', 'quote', 'cash', 'customer', 'reports'],
        'warehouse' => ['stock', 'receive', 'transfer', 'count', 'product'],
        'purchaser' => ['purchase', 'request', 'supplier', 'stock', 'reports'],
        'accountant' => ['accounting', 'reports', 'cash', 'purchase'],
        'management' => ['reports', 'sell', 'stock', 'cash', 'accounting'],
        'member' => ['sell', 'quote', 'cash', 'purchase', 'product', 'stock', 'reports', 'accounting'],
    ];

    private const int MAX_ACTIONS = 6;

    public function __invoke(Request $request, TenantContext $context, StockAccess $access, AlertCenter $center, NotificationPreferences $preferences, StartGuide $guide, FeatureAccess $features): Response|RedirectResponse
    {
        $membership = $context->membership();
        abort_if($membership === null, 403);

        // Fortify et les liens historiques arrivent sur /dashboard. Cette
        // redirection centrale garantit qu'une appartenance en mode simple
        // n'ouvre jamais involontairement l'interface experte.
        if ($membership->homeRoute() === 'simple.home') {
            return to_route('simple.home');
        }

        /** @var User $user */
        $user = $request->user();
        $pending = $center->pending($user);
        $visible = array_keys(array_filter($preferences->for($user, array_keys($pending)), fn (array $p) => $p['in_app']));
        $alerts = array_intersect_key($pending, array_flip($visible));

        return Inertia::render('Dashboard', [
            'figures' => $this->figures($context, $access),
            'alerts' => $alerts,
            'actions' => $this->actions($guide->track(), $features->hasFeature($context->tenant(), FeatureAccess::FEATURE_ACCOUNTING)),
        ]);
    }

    /**
     * @return list<string>
     */
    private function actions(string $track, bool $accounting): array
    {
        $allowed = [
            'sell' => Gate::allows('sale.create') && Gate::allows('sale.validate'),
            'quote' => Gate::allows('sale.create'),
            'cash' => Gate::any(['cash.view_own', 'cash.view_all']),
            'purchase' => Gate::allows('purchase.view'),
            'product' => Gate::allows('catalog.create'),
            'stock' => Gate::allows('inventory.view'),
            'reports' => Gate::any(['report.view_own', 'report.view_all']),
            'accounting' => $accounting && Gate::allows('accounting.view'),
            'customer' => Gate::allows('customer.manage'),
            'supplier' => Gate::allows('supplier.manage'),
            'receive' => Gate::allows('purchase.receive'),
            'transfer' => Gate::allows('inventory.transfer_create'),
            'count' => Gate::allows('inventory.count_create'),
            'request' => Gate::allows('purchase_request.create'),
        ];

        return array_slice(
            array_values(array_filter(self::ACTION_ORDER[$track] ?? self::ACTION_ORDER['member'], fn (string $key): bool => $allowed[$key])),
            0,
            self::MAX_ACTIONS,
        );
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
}
