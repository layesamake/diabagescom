<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingReports;
use App\Domain\Accounting\BankReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use App\Models\ChartAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function index(Request $request, BankReconciliationService $service, AccountingReports $reports): Response
    {
        Gate::authorize('bank.view');

        $accounts = ChartAccount::query()->where('reconcilable', true)->where('active', true)->orderBy('code')->get(['id', 'code', 'name']);
        /** @var ChartAccount|null $account */
        $account = $accounts->firstWhere('code', (string) $request->query('account', '')) ?? $accounts->first();
        /** @var BankReconciliation|null $current */
        $current = $account !== null ? BankReconciliation::query()->where('account_id', $account->id)->where('status', 'draft')->first() : null;

        $lines = [];

        if ($account !== null && $current !== null) {
            $lines = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
                ->leftJoin('bank_reconciliation_lines as r', 'r.line_id', '=', 'l.id')
                ->leftJoin('bank_reconciliations as b', 'b.id', '=', 'r.reconciliation_id')
                ->where('l.account_id', $account->id)->where('e.status', 'posted')
                ->whereDate('e.entry_date', '<=', $current->statement_date->toDateString())
                ->where(fn ($q) => $q->whereNull('r.line_id')->orWhere('b.id', $current->id))
                ->orderBy('e.entry_date')->limit(1000)
                ->get(['l.id', 'e.entry_date', 'e.number', 'l.label', 'l.debit', 'l.credit', 'r.line_id as checked'])
                ->map(fn ($l) => [
                    'id' => (string) $l->id, 'date' => (string) $l->entry_date, 'number' => (string) $l->number, 'label' => (string) $l->label,
                    'debit' => (int) $l->debit, 'credit' => (int) $l->credit, 'checked' => $l->checked !== null,
                ])->values()->all();
        }

        return Inertia::render('accounting/Reconciliation', [
            'accounts' => $accounts->map->only(['code', 'name'])->values(),
            'account' => $account?->only(['code', 'name']),
            'book_balance' => $account !== null ? $reports->balance($account->id, CarbonImmutable::today()) : 0,
            'current' => $current === null ? null : [
                'id' => $current->id,
                'statement_date' => $current->statement_date->toDateString(),
                'statement_balance' => $current->statement_balance,
                'reference' => $current->reference,
                'reconciled' => $service->reconciledBalance($current),
            ],
            'lines' => $lines,
            'history' => $account !== null ? BankReconciliation::query()->where('account_id', $account->id)->where('status', 'completed')
                ->orderByDesc('statement_date')->limit(10)->get(['id', 'statement_date', 'statement_balance', 'reference'])
                ->map(fn (BankReconciliation $r) => ['id' => $r->id, 'statement_date' => $r->statement_date->toDateString(), 'statement_balance' => $r->statement_balance, 'reference' => $r->reference])
                ->values() : [],
            'can' => ['reconcile' => Gate::allows('bank.reconcile')],
        ]);
    }

    public function store(Request $request, BankReconciliationService $service): RedirectResponse
    {
        Gate::authorize('bank.reconcile');
        $data = $request->validate([
            'account' => ['required', 'string'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'integer', 'min:-999999999999', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:64'],
        ], attributes: ['statement_balance' => 'solde du relevé']);

        $account = ChartAccount::query()->where('code', $data['account'])->firstOrFail();

        /** @var User $user */
        $user = $request->user();
        $service->start($account, CarbonImmutable::parse((string) $data['statement_date']), (int) $data['statement_balance'], $data['reference'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.reconciliation')]);

        return to_route('accounting.reconciliations.index', ['account' => $account->code]);
    }

    public function toggle(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        Gate::authorize('bank.reconcile');
        $data = $request->validate(['lines' => ['required', 'array', 'max:500'], 'lines.*' => ['uuid'], 'checked' => ['required', 'boolean']]);
        $service->toggle($reconciliation, array_values(array_map('strval', $data['lines'])), (bool) $data['checked']);

        return back(303);
    }

    public function complete(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        Gate::authorize('bank.reconcile');

        /** @var User $user */
        $user = $request->user();
        $service->complete($reconciliation, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.reconciliation_completed')]);

        return back(303);
    }
}
