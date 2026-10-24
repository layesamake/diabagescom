<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\StockAccess;
use App\Domain\Reports\PilotageReport;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — résultats du commerce, adaptés du read model Pilotage commun.
 */
class SimpleResultsController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(
        Request $request,
        TenantContext $context,
        StockAccess $access,
        PilotageReport $pilotage,
    ): Response {
        abort_unless(Gate::any(['report.view_own', 'report.view_all']), 403);
        /** @var User $user */
        $user = $request->user();
        $timezone = $context->tenant()->timezone;
        $today = CarbonImmutable::now($timezone);
        $key = in_array($request->query('period'), ['week', 'month', 'year'], true)
            ? (string) $request->query('period')
            : 'month';
        $from = match ($key) {
            'week' => $today->startOfWeek(),
            'year' => $today->startOfYear(),
            default => $today->startOfMonth(),
        };
        $to = $today->endOfDay();
        $days = $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $previousTo = $from->subDay()->endOfDay();
        $previousFrom = $previousTo->startOfDay()->subDays($days - 1);
        $all = Gate::allows('report.view_all');
        $margin = Gate::allows('report.margin_view');
        $data = $pilotage->build(
            $user,
            $all,
            $margin,
            Gate::allows('inventory.view'),
            Gate::allows('purchase.view'),
            $access->accessibleIds(),
            null,
            $from->utc(),
            $to->utc(),
            $previousFrom->utc(),
            $previousTo->utc(),
            $timezone,
        );
        $summary = $data['summary'];

        return Inertia::render('simple/Resultats', [
            'period' => [
                'key' => $key,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'sold' => $summary['total'],
                'spent' => $summary['cost'],
                'remaining' => $summary['margin'],
            ],
            'comparison' => $data['comparison'],
            'by_method' => $data['byMethod']->values(),
            'top_products' => $data['topProducts']->values(),
            'can' => ['margin' => $margin],
            'audio_code' => 'PIL-01',
        ]);
    }
}
