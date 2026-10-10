<?php

namespace App\Http\Controllers\Cash;

use App\Domain\Cash\CashService;
use App\Domain\Inventory\StockAccess;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Caisses et sessions. cash.view_own : uniquement les sessions ouvertes par
 * l'utilisateur ; cash.view_all : toutes celles des dépôts accessibles.
 */
class CashController extends Controller
{
    public function index(Request $request, StockAccess $access): Response
    {
        $this->authorizeView();
        $ids = $access->accessibleIds();
        /** @var User $user */
        $user = $request->user();

        $registers = CashRegister::query()->with(['warehouse:id,code,name', 'openSession.opener:id,name'])
            ->whereIn('warehouse_id', $ids)->where('active', true)->orderBy('name')->get()
            ->map(fn (CashRegister $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'warehouse' => $r->warehouse->only(['id', 'code', 'name']),
                'session' => $r->openSession !== null ? [
                    'id' => $r->openSession->id,
                    'opened_by' => $r->openSession->opener->name,
                    'opened_at' => $r->openSession->opened_at->toIso8601String(),
                    'mine' => $r->openSession->opened_by === $user->id,
                ] : null,
            ]);

        $sessions = $this->visibleSessions($ids, $user)
            ->with(['register:id,name', 'opener:id,name', 'closer:id,name'])
            ->latest('opened_at')
            ->paginate(20)
            ->through(fn (CashSession $s) => $this->summary($s));

        return Inertia::render('cash/Registers', [
            'registers' => $registers,
            'sessions' => $sessions,
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'can' => [
                'open_close' => Gate::allows('cash.open_close'),
                'manage' => Gate::allows('warehouse.manage'),
            ],
        ]);
    }

    public function storeRegister(Request $request, CashService $cash): RedirectResponse
    {
        Gate::authorize('warehouse.manage');
        $data = $request->validate(['warehouse_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:80']], attributes: ['name' => 'nom']);

        /** @var User $user */
        $user = $request->user();
        $cash->createRegister((string) $data['warehouse_id'], (string) $data['name'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('cash.saved.register')]);

        return back(303);
    }

    public function open(Request $request, CashRegister $register, CashService $cash): RedirectResponse
    {
        Gate::authorize('cash.open_close');
        $data = $request->validate(['opening_amount' => ['required', 'integer', 'min:0', 'max:999999999999']], attributes: ['opening_amount' => 'fond de caisse']);

        /** @var User $user */
        $user = $request->user();
        $session = $cash->open($register, (int) $data['opening_amount'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('cash.saved.opened')]);

        return $request->boolean('to_pos')
            ? to_route('pos.create', ['warehouse' => $register->warehouse_id])
            : to_route('cash.sessions.show', $session);
    }

    public function show(Request $request, CashSession $session, StockAccess $access, CashService $cash): Response
    {
        $this->authorizeView();
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->visibleSessions($access->accessibleIds(), $user)->whereKey($session->id)->exists(), 404);

        $session->load(['register.warehouse:id,code,name', 'opener:id,name', 'closer:id,name']);
        $isOpen = $session->status === CashSession::OPEN;

        return Inertia::render('cash/SessionShow', [
            'session' => [
                ...$this->summary($session),
                'warehouse' => $session->register->warehouse->only(['id', 'code', 'name']),
                'closing_note' => $session->closing_note,
                'summary' => $cash->summary($session),
                'movements' => CashMovement::query()->with('author:id,name')->where('session_id', $session->id)->orderBy('occurred_at')->get()
                    ->map(fn (CashMovement $m) => [
                        'id' => $m->id,
                        'type' => $m->type,
                        'amount' => $m->amount,
                        'number' => $m->number,
                        'label' => $m->label,
                        'reason' => $m->reason,
                        'occurred_at' => $m->occurred_at->toIso8601String(),
                        'author' => $m->author->name,
                    ])->values(),
                'payments' => Payment::query()->with('salesDocument:id,number,type')->where('cash_session_id', $session->id)->where('method', '<>', 'cash')->orderBy('paid_at')->get()
                    ->map(fn (Payment $p) => [
                        'id' => $p->id,
                        'number' => $p->number,
                        'direction' => $p->direction,
                        'method' => $p->method,
                        'amount' => $p->amount,
                        'reference' => $p->reference,
                        'document' => $p->salesDocument?->only(['id', 'number']),
                        'paid_at' => $p->paid_at->toIso8601String(),
                    ])->values(),
            ],
            'can' => [
                'close' => $isOpen && Gate::allows('cash.open_close'),
                'expense' => $isOpen && Gate::allows('cash.expense'),
                'income' => $isOpen && Gate::allows('cash.receive'),
                'sell' => $isOpen && Gate::allows('sale.create') && Gate::allows('sale.validate'),
            ],
        ]);
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
        Inertia::flash('toast', ['type' => 'success', 'message' => __("cash.saved.{$data['type']}")]);

        return back(303);
    }

    public function close(Request $request, CashSession $session, CashService $cash): RedirectResponse
    {
        Gate::authorize('cash.open_close');
        $data = $request->validate([
            'counted_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['counted_amount' => 'montant compté']);

        /** @var User $user */
        $user = $request->user();
        $cash->close($session, (int) $data['counted_amount'], $data['note'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('cash.saved.closed')]);

        return to_route('cash.sessions.show', $session);
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::any(['cash.view_own', 'cash.view_all']), 403);
    }

    /**
     * @param  list<string>  $warehouseIds
     * @return Builder<CashSession>
     */
    private function visibleSessions(array $warehouseIds, User $user): Builder
    {
        return CashSession::query()
            ->whereHas('register', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when(! Gate::allows('cash.view_all'), fn ($q) => $q->where('opened_by', $user->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CashSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'register' => $session->register->name,
            'opening_amount' => $session->opening_amount,
            'expected_amount' => $session->expected_amount,
            'counted_amount' => $session->counted_amount,
            'difference' => $session->difference,
            'opened_by' => $session->opener->name,
            'opened_at' => $session->opened_at->toIso8601String(),
            'closed_by' => $session->closer?->name,
            'closed_at' => $session->closed_at?->toIso8601String(),
        ];
    }
}
