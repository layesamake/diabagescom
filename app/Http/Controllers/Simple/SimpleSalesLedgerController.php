<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Sales\SalesDayLedger;
use App\Domain\Sales\SalesDocumentService;
use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 3 « Mes ventes ».
 *
 * Façade mince autour de SalesDayLedger et SalesDocumentService : un vendeur
 * voit toujours ses propres ventes du jour choisi, un administrateur voit
 * toutes les ventes du dépôt. Annuler reste réservé à sale.cancel (admin).
 */
class SimpleSalesLedgerController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(Request $request, SalesDayLedger $ledger): Response
    {
        Gate::authorize('sale.view');

        $day = $this->resolveDay($request);
        /** @var User $user */
        $user = $request->user();
        $all = Gate::allows('report.view_all');

        return Inertia::render('simple/Ventes', [
            ...$ledger->detailForDay($day, $user, $all),
            'can' => ['cancel' => Gate::allows('sale.cancel')],
        ]);
    }

    public function cancel(Request $request, SalesDocument $document, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.cancel');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $service->cancel($document, $data['reason'] ?? null, $user);

        return to_route('simple.sales');
    }

    private function resolveDay(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('day', '');

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) === 1) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $raw)->startOfDay();
            } catch (\Exception) {
                // Date invalide (ex. 2026-02-30) : on retombe sur aujourd'hui.
            }
        }

        return CarbonImmutable::today();
    }
}
