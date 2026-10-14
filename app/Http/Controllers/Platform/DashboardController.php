<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Onboarding\StartGuide;
use App\Domain\Subscriptions\PlatformMetrics;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord du propriétaire de la plateforme : revenu récurrent, essais,
 * conversions, encaissements, impayés et relances à faire.
 */
class DashboardController extends Controller
{
    public function __invoke(PlatformMetrics $metrics, StartGuide $guide): Response
    {
        Gate::authorize('platform.tenants.manage');

        return Inertia::render('platform/Dashboard', [
            'metrics' => $metrics->snapshot(),
            'activation' => $guide->funnel(),
            'stalled' => $guide->stalled(),
            'canManageBilling' => Gate::allows('platform.billing.manage'),
        ]);
    }
}
