<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingPoster;
use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\FiscalCalendar;
use App\Domain\Accounting\InvalidAccountingOperation;
use App\Domain\Accounting\YearEndClosing;
use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\AccountingSetting;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord comptable : activation, comptabilisation, périodes, clôtures
 * et trace de la validation par l'expert-comptable.
 */
class AccountingController extends Controller
{
    public function index(AccountingPoster $poster): Response
    {
        Gate::authorize('accounting.view');

        /** @var AccountingSetting|null $settings */
        $settings = AccountingSetting::query()->first();

        return Inertia::render('accounting/Overview', [
            'settings' => $settings === null ? null : [
                'posting_starts_on' => $settings->posting_starts_on->toDateString(),
                'expert_name' => $settings->expert_name,
                'expert_validated_at' => $settings->expert_validated_at?->toIso8601String(),
            ],
            'pending' => $settings !== null ? $poster->pendingCount() : 0,
            'entries' => JournalEntry::query()->where('status', JournalEntry::POSTED)->count(),
            'drafts' => JournalEntry::query()->where('status', JournalEntry::DRAFT)->count(),
            'years' => FiscalYear::query()->with('periods')->orderByDesc('starts_on')->get()->map(fn (FiscalYear $y) => [
                'id' => $y->id,
                'name' => $y->name,
                'starts_on' => $y->starts_on->toDateString(),
                'ends_on' => $y->ends_on->toDateString(),
                'status' => $y->status,
                'periods' => $y->periods->map(fn (AccountingPeriod $p) => [
                    'id' => $p->id,
                    'starts_on' => $p->starts_on->toDateString(),
                    'status' => $p->status,
                ])->values(),
            ])->values(),
            'can' => [
                'settings' => Gate::allows('accounting.settings'),
                'post' => Gate::allows('accounting.post'),
                'close' => Gate::allows('accounting.period_close'),
            ],
        ]);
    }

    public function initialize(Request $request, AccountingSetup $setup): RedirectResponse
    {
        Gate::authorize('accounting.settings');
        $data = $request->validate([
            'fiscal_year_start' => ['required', 'date'],
            'posting_starts_on' => ['required', 'date'],
        ], attributes: ['fiscal_year_start' => 'début d’exercice', 'posting_starts_on' => 'date de début de comptabilisation']);

        /** @var User $user */
        $user = $request->user();
        $setup->initialize($user, CarbonImmutable::parse((string) $data['fiscal_year_start']), CarbonImmutable::parse((string) $data['posting_starts_on']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.initialized')]);

        return back(303);
    }

    public function postPending(AccountingPoster $poster): RedirectResponse
    {
        Gate::authorize('accounting.post');
        $result = $poster->postPending();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.posted', ['count' => $result['posted']])]);

        return back(303);
    }

    public function expert(Request $request, AccountingSetup $setup): RedirectResponse
    {
        Gate::authorize('accounting.settings');
        $data = $request->validate(['expert_name' => ['required', 'string', 'min:3', 'max:120']], attributes: ['expert_name' => 'nom de l’expert-comptable']);

        /** @var User $user */
        $user = $request->user();
        $setup->recordExpertValidation((string) $data['expert_name'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.expert')]);

        return back(303);
    }

    public function period(Request $request, AccountingPeriod $period, FiscalCalendar $calendar): RedirectResponse
    {
        Gate::authorize('accounting.period_close');
        $action = (string) $request->validate(['action' => ['required', 'in:lock,unlock,close']])['action'];

        /** @var User $user */
        $user = $request->user();
        $period = match ($action) {
            'lock' => $calendar->lock($period, $user),
            'unlock' => $calendar->unlock($period, $user),
            default => $calendar->close($period, $user),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.period', ['status' => __("accounting.period_status.{$period->status}")])]);

        return back(303);
    }

    public function createYear(Request $request, FiscalCalendar $calendar): RedirectResponse
    {
        Gate::authorize('accounting.settings');

        /** @var FiscalYear|null $last */
        $last = FiscalYear::query()->orderByDesc('ends_on')->first();

        if ($last === null) {
            throw new InvalidAccountingOperation(__('accounting.errors.not_initialized'));
        }

        $calendar->ensureFiscalYear($last->ends_on->addDay());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.fiscal_year')]);

        return back(303);
    }

    public function closeYear(Request $request, FiscalYear $year, YearEndClosing $closing): RedirectResponse
    {
        Gate::authorize('accounting.period_close');

        /** @var User $user */
        $user = $request->user();
        $closing->close($year, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.year_closed')]);

        return back(303);
    }
}
