<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Cash\CashService;
use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\PointOfSale;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 2 « Journée de vente ».
 *
 * Façades minces autour de PointOfSale et CashService : aucune logique métier
 * ici, seulement la préparation des props Inertia, la validation HTTP, les
 * autorisations et les redirections vers les écrans du mode simple.
 */
class SimpleSalesController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    // ── Vendre ──────────────────────────────────────────────────────────

    public function sell(StockAccess $access, TenantContext $context): Response
    {
        Gate::authorize('sale.create');
        Gate::authorize('sale.validate');

        $warehouses = $access->accessible();
        $warehouse = $warehouses->firstWhere('id', $context->warehouse()?->id)
            ?? $warehouses->first();

        return Inertia::render('simple/Vendre', [
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
            'warehouse' => $warehouse?->only(['id', 'code', 'name']),
            'sessions' => $warehouse !== null ? SalesDocumentController::openSessions($warehouse->id) : [],
            'registers' => $warehouse !== null ? CashRegister::query()
                ->where('warehouse_id', $warehouse->id)->where('active', true)
                ->whereDoesntHave('openSession')->orderBy('name')->get(['id', 'name']) : [],
            'methods' => Payment::METHODS,
            'frequent' => [], // Les articles fréquents seront ajoutés avec la grille photo.
            'can' => [
                'price_override' => Gate::allows('catalog.price_override'),
                'discount' => Gate::allows('sale.discount_standard') || Gate::allows('sale.discount_exception'),
                'negative_stock' => Gate::allows('sale.sell_negative_stock'),
                'open_cash' => Gate::allows('cash.open_close'),
            ],
        ]);
    }

    public function storeSale(Request $request, PointOfSale $pos): RedirectResponse
    {
        Gate::authorize('sale.create');
        Gate::authorize('sale.validate');

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'cash_session_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'negative_stock_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'tenders' => ['present', 'array', 'max:6'],
            'tenders.*.method' => ['required', Rule::in(Payment::METHODS)],
            'tenders.*.amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
        ], attributes: ['cash_session_id' => 'caisse', 'lines' => 'articles']);

        $tenders = SalesDocumentController::tenders($data['tenders']);

        if (array_filter($tenders, fn (array $t) => $t['amount'] > 0) !== []) {
            Gate::authorize('cash.receive');
        }

        /** @var User $user */
        $user = $request->user();
        $sale = $pos->checkout([
            'warehouse_id' => (string) $data['warehouse_id'],
            'customer_id' => isset($data['customer_id']) ? (string) $data['customer_id'] : null,
            'cash_session_id' => (string) $data['cash_session_id'],
            'negative_stock_reason' => isset($data['negative_stock_reason']) ? (string) $data['negative_stock_reason'] : null,
            'lines' => SalesDocumentController::lines($data['lines']),
            'tenders' => $tenders,
        ], $user, (string) $data['idempotency_key']);

        Inertia::flash('sale', [
            'number' => $sale->number,
            'total' => $sale->total,
            'amount_paid' => $sale->amount_paid,
        ]);

        return to_route('simple.sell');
    }

    // ── Caisse ──────────────────────────────────────────────────────────

    public function cash(Request $request, StockAccess $access, TenantContext $context, CashService $cashService): Response
    {
        abort_unless(Gate::any(['cash.view_own', 'cash.view_all']), 403);

        $warehouse = $access->accessible()->firstWhere('id', $context->warehouse()?->id)
            ?? $access->accessible()->first();

        /** @var User $user */
        $user = $request->user();

        $register = $warehouse !== null ? CashRegister::query()
            ->with(['openSession.opener:id,name'])
            ->where('warehouse_id', $warehouse->id)
            ->where('active', true)
            ->first() : null;

        $session = $register?->openSession;
        $mine = $session !== null && $session->opened_by === $user->id;
        $canOperate = $mine || Gate::allows('cash.view_all');

        return Inertia::render('simple/Caisse', [
            'warehouse' => $warehouse?->only(['id', 'code', 'name']),
            'register' => $register !== null ? ['id' => $register->id, 'name' => $register->name] : null,
            'session' => $session !== null && $canOperate ? [
                'id' => $session->id,
                'status' => $session->status,
                'opening_amount' => $session->opening_amount,
                'opened_by' => $session->opener->name,
                'opened_at' => $session->opened_at->toIso8601String(),
                'summary' => $cashService->summary($session),
                'drawer' => $cashService->drawerBalance($session),
            ] : null,
            'can' => [
                'open' => Gate::allows('cash.open_close'),
                'close' => $canOperate && $session !== null && Gate::allows('cash.open_close'),
                'income' => $canOperate && $session !== null && Gate::allows('cash.receive'),
                'expense' => $canOperate && $session !== null && Gate::allows('cash.expense'),
            ],
        ]);
    }

    public function openRegister(Request $request, CashRegister $register, CashService $cash): RedirectResponse
    {
        Gate::authorize('cash.open_close');
        $data = $request->validate([
            'opening_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], attributes: ['opening_amount' => 'fond de caisse']);

        /** @var User $user */
        $user = $request->user();
        $cash->open($register, (int) $data['opening_amount'], $user);

        return to_route('simple.cash');
    }

    public function closeSession(Request $request, CashSession $session, CashService $cash, TenantContext $context): RedirectResponse
    {
        Gate::authorize('cash.open_close');

        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $session->opened_by === $user->id || Gate::allows('cash.view_all'),
            403,
        );

        $data = $request->validate([
            'counted_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['counted_amount' => 'montant compté']);

        $cash->close($session, (int) $data['counted_amount'], $data['note'] ?? null, $user);

        return to_route('simple.cash');
    }

    public function movement(Request $request, CashSession $session, CashService $cash): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['expense', 'income'])],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'label' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], attributes: ['label' => 'libellé', 'reason' => 'motif', 'amount' => 'montant']);
        Gate::authorize($data['type'] === 'expense' ? 'cash.expense' : 'cash.receive');

        /** @var User $user */
        $user = $request->user();
        $cash->recordOther($session->id, (string) $data['type'], (int) $data['amount'], (string) $data['label'], (string) $data['reason'], $user, (string) $data['idempotency_key']);

        return to_route('simple.cash');
    }
}
