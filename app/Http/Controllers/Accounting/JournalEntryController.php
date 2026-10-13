<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\EntryAttachments;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\InvalidAccountingOperation;
use App\Domain\Accounting\Ledger;
use App\Http\Controllers\Controller;
use App\Models\AccountingJournal;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryAttachment;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalEntryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('accounting.view');

        $filters = [
            'journal' => (string) $request->query('journal', ''),
            'status' => in_array($request->query('status'), ['draft', 'posted'], true) ? (string) $request->query('status') : '',
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'q' => trim((string) $request->query('q', '')),
        ];

        $entries = JournalEntry::query()->with('journal:id,code,name')
            ->withSum('lines', 'debit')
            ->when($filters['journal'] !== '', fn ($q) => $q->whereHas('journal', fn ($j) => $j->where('code', $filters['journal'])))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate('entry_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate('entry_date', '<=', $filters['to']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('upper(number) LIKE upper(?)', ['%'.addcslashes($filters['q'], '%_\\').'%'])
                ->orWhereRaw('diaba_search_text(description) LIKE diaba_search_text(?)', ['%'.addcslashes($filters['q'], '%_\\').'%'])))
            ->orderByDesc('entry_date')->orderByDesc('number')
            ->paginate(30)->withQueryString()
            ->through(fn (JournalEntry $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'journal' => $e->journal->code,
                'entry_date' => $e->entry_date->toDateString(),
                'description' => $e->description,
                'status' => $e->status,
                'amount' => (int) $e->getAttribute('lines_sum_debit'),
                'automatic' => $e->source_type !== null,
            ]);

        return Inertia::render('accounting/Entries', [
            'entries' => $entries,
            'filters' => $filters,
            'journals' => AccountingJournal::query()->orderBy('code')->get(['code', 'name']),
            'can' => ['create' => Gate::allows('accounting.manual_entry')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('accounting.manual_entry');

        return Inertia::render('accounting/EntryForm', ['entry' => null, ...$this->formData()]);
    }

    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');
        [$journal, $date, $description, $lines] = $this->validated($request);

        /** @var User $user */
        $user = $request->user();
        $entry = $ledger->saveDraft(null, $journal, $date, $description, $lines, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.draft')]);

        return to_route('accounting.entries.show', $entry);
    }

    public function edit(JournalEntry $entry): Response|RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');

        if ($entry->status !== JournalEntry::DRAFT) {
            return to_route('accounting.entries.show', $entry);
        }

        $entry->load(['lines.account:id,code', 'journal:id,code']);

        return Inertia::render('accounting/EntryForm', [
            'entry' => [
                'id' => $entry->id,
                'journal' => $entry->journal->code,
                'entry_date' => $entry->entry_date->toDateString(),
                'description' => $entry->description,
                'lines' => $entry->lines->map(fn (JournalEntryLine $l) => [
                    'account' => $l->account->code, 'label' => $l->label, 'debit' => $l->debit, 'credit' => $l->credit,
                ])->values(),
            ],
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, JournalEntry $entry, Ledger $ledger): RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');
        [$journal, $date, $description, $lines] = $this->validated($request);

        /** @var User $user */
        $user = $request->user();
        $ledger->saveDraft($entry, $journal, $date, $description, $lines, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.draft')]);

        return to_route('accounting.entries.show', $entry);
    }

    public function show(JournalEntry $entry): Response
    {
        Gate::authorize('accounting.view');
        $entry->load(['lines.account:id,code,name', 'journal:id,code,name', 'period:id,starts_on,status', 'reversal:id,number,reversal_of_id', 'reversalOf:id,number']);
        $attachments = JournalEntryAttachment::query()->where('entry_id', $entry->id)->orderBy('created_at')->get();

        return Inertia::render('accounting/EntryShow', [
            'entry' => [
                'id' => $entry->id,
                'number' => $entry->number,
                'journal' => $entry->journal->only(['code', 'name']),
                'entry_date' => $entry->entry_date->toDateString(),
                'description' => $entry->description,
                'status' => $entry->status,
                'source' => $entry->source_type !== null ? ['type' => $entry->source_type, 'id' => $entry->source_id, 'kind' => $entry->source_kind] : null,
                'reversal' => $entry->reversal?->only(['id', 'number']),
                'reversal_of' => $entry->reversalOf?->only(['id', 'number']),
                'lines' => $entry->lines->map(fn (JournalEntryLine $l) => [
                    'id' => $l->id,
                    'account' => $l->account->code,
                    'account_name' => $l->account->name,
                    'label' => $l->label,
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                ])->values(),
                'attachments' => $attachments->map(fn (JournalEntryAttachment $a) => [
                    'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                ])->values(),
            ],
            'can' => [
                'attach' => Gate::allows('accounting.manual_entry'),
                'detach' => $entry->status === JournalEntry::DRAFT && Gate::allows('accounting.manual_entry'),
                'edit' => $entry->status === JournalEntry::DRAFT && Gate::allows('accounting.manual_entry'),
                'post' => $entry->status === JournalEntry::DRAFT && Gate::allows('accounting.post'),
                'reverse' => $entry->status === JournalEntry::POSTED && $entry->reversal === null && $entry->reversal_of_id === null && Gate::allows('accounting.post'),
            ],
        ]);
    }

    public function attach(Request $request, JournalEntry $entry, EntryAttachments $attachments): RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');
        $data = $request->validate(['file' => ['required', 'file']], attributes: ['file' => 'pièce']);

        /** @var User $user */
        $user = $request->user();
        $attachments->add($entry, $data['file'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.attachments.added')]);

        return to_route('accounting.entries.show', $entry);
    }

    public function download(JournalEntry $entry, JournalEntryAttachment $attachment, EntryAttachments $attachments): StreamedResponse
    {
        Gate::authorize('accounting.view');
        abort_unless($attachment->entry_id === $entry->id, 404);

        return $attachments->disk()->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function detach(Request $request, JournalEntry $entry, JournalEntryAttachment $attachment, EntryAttachments $attachments): RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');
        abort_unless($attachment->entry_id === $entry->id, 404);

        /** @var User $user */
        $user = $request->user();
        $attachments->remove($entry, $attachment, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.attachments.removed')]);

        return to_route('accounting.entries.show', $entry);
    }

    public function post(Request $request, JournalEntry $entry, Ledger $ledger): RedirectResponse
    {
        Gate::authorize('accounting.post');

        /** @var User $user */
        $user = $request->user();
        $entry = $ledger->postDraft($entry, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.entry_posted', ['number' => $entry->number])]);

        return to_route('accounting.entries.show', $entry);
    }

    public function reverse(Request $request, JournalEntry $entry, Ledger $ledger): RedirectResponse
    {
        Gate::authorize('accounting.post');
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:3', 'max:200']], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $reversal = $ledger->reverse($entry, CarbonImmutable::parse((string) $data['date']), (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.reversed', ['number' => $reversal->number])]);

        return to_route('accounting.entries.show', $reversal);
    }

    public function destroy(JournalEntry $entry): RedirectResponse
    {
        Gate::authorize('accounting.manual_entry');

        if ($entry->status !== JournalEntry::DRAFT) {
            throw new InvalidAccountingOperation(__('accounting.errors.draft_only'));
        }

        $entry->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.deleted')]);

        return to_route('accounting.entries.index');
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: string, 3: list<EntryLine>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'journal' => ['required', 'string', 'max:8'],
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account' => ['required', 'string', 'max:20'],
            'lines.*.label' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], attributes: ['description' => 'libellé', 'lines' => 'lignes']);

        $description = (string) $data['description'];
        $lines = array_values(array_map(fn (array $l) => new EntryLine(
            (string) $l['account'], (int) ($l['debit'] ?? 0), (int) ($l['credit'] ?? 0),
            filled($l['label'] ?? null) ? (string) $l['label'] : $description,
        ), $data['lines']));

        return [(string) $data['journal'], CarbonImmutable::parse((string) $data['entry_date']), $description, $lines];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'journals' => AccountingJournal::query()->where('active', true)->whereNot('type', 'opening')->orderBy('code')->get(['code', 'name']),
            'accounts' => ChartAccount::query()->where('active', true)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
