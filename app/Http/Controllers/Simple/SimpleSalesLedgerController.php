<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\Quantity;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\SalesDayLedger;
use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
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
 * Façade mince autour de SalesDayLedger et CreditNoteService : un vendeur
 * voit toujours ses propres ventes du jour choisi, un administrateur voit
 * toutes les ventes du dépôt.
 *
 * « Annuler » une facture déjà validée n'efface jamais la vente (règle du
 * mode expert, docs/05) : c'est un avoir qui porte sur toutes les lignes et
 * remet les articles en stock, réservé à sale.cancel (admin).
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

    public function cancel(Request $request, SalesDocument $document, CreditNoteService $credits): RedirectResponse
    {
        Gate::authorize('sale.cancel');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $lines = $document->lines->map(fn (SalesDocumentLine $line) => [
            'line_id' => $line->id,
            'quantity' => Quantity::of($line->quantity)->format(),
            'restock' => true,
        ])->values()->all();

        $credits->create($document, $lines, (string) $data['reason'], $user, (string) $data['idempotency_key']);

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
