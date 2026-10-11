<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture du grand livre (partie double).
 *
 * Toute écriture validée est équilibrée (contrôlé ici et par une contrainte
 * différée en base), numérotée par journal et exercice, datée dans une période
 * ouverte, puis immuable. Une erreur se corrige par une extourne liée.
 */
final class Ledger
{
    /** @var array<string, ChartAccount> */
    private array $accounts = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly FiscalCalendar $calendar,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Passe et valide une écriture.
     *
     * @param  list<EntryLine>  $lines
     * @param  array{type: string, id: string, kind: string}|null  $source
     * @param  bool  $shiftToOpenPeriod  écriture automatique : si la période est fermée, report au premier jour ouvert suivant
     */
    public function post(string $journalCode, CarbonImmutable $date, string $description, array $lines, ?User $actor, ?array $source = null, bool $shiftToOpenPeriod = false, ?string $reversalOf = null): JournalEntry
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($journalCode, $date, $description, $lines, $actor, $source, $shiftToOpenPeriod, $reversalOf): JournalEntry {
            [$period, $date] = $this->resolvePeriod($date, $shiftToOpenPeriod);

            $entry = new JournalEntry;
            $entry->forceFill([
                'journal_id' => $this->journal($journalCode)->id,
                'period_id' => $period->id,
                'entry_date' => $date->toDateString(),
                'description' => mb_substr($description, 0, 255),
                'source_type' => $source['type'] ?? null,
                'source_id' => $source['id'] ?? null,
                'source_kind' => $source['kind'] ?? null,
                'reversal_of_id' => $reversalOf,
                'created_by' => $actor?->id,
            ])->save();

            $this->writeLines($entry, $lines);

            return $this->finalize($entry, $actor);
        });
    }

    /**
     * Valide un brouillon (saisie manuelle) : équilibre et période ouverte requis.
     */
    public function postDraft(JournalEntry $draft, User $actor): JournalEntry
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($draft, $actor): JournalEntry {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($draft->id);

            if ($entry->status === JournalEntry::POSTED) {
                return $entry;
            }

            $this->resolvePeriod($entry->entry_date, false);
            $lines = $entry->lines()->with('account')->get()
                ->map(fn (JournalEntryLine $l) => new EntryLine($l->account->code, $l->debit, $l->credit, $l->label, $l->party_type, $l->party_id))
                ->values()->all();
            /** @var list<EntryLine> $lines */
            $this->assertBalanced($lines);

            return $this->finalize($entry, $actor);
        });
    }

    /**
     * Brouillon d'écriture diverse (journal OD par défaut), modifiable avant validation.
     *
     * @param  list<EntryLine>  $lines
     */
    public function saveDraft(?JournalEntry $draft, string $journalCode, CarbonImmutable $date, string $description, array $lines, User $actor): JournalEntry
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($draft, $journalCode, $date, $description, $lines, $actor): JournalEntry {
            [$period] = $this->resolvePeriod($date, false);

            if ($draft !== null) {
                /** @var JournalEntry $entry */
                $entry = JournalEntry::query()->lockForUpdate()->findOrFail($draft->id);

                if ($entry->status !== JournalEntry::DRAFT) {
                    throw new InvalidAccountingOperation(__('accounting.errors.posted_immutable'));
                }

                $entry->lines()->delete();
            } else {
                $entry = new JournalEntry;
                $entry->created_by = $actor->id;
            }

            $entry->forceFill([
                'journal_id' => $this->journal($journalCode)->id,
                'period_id' => $period->id,
                'entry_date' => $date->toDateString(),
                'description' => mb_substr($description, 0, 255),
            ])->save();

            $this->writeLines($entry, $lines, requireBalance: false);
            $this->audit->record('accounting.draft_saved', $entry, after: ['lines' => count($lines)], actor: $actor);

            return $entry;
        });
    }

    /**
     * Extourne : écriture inverse liée à l'originale, dans une période ouverte.
     */
    public function reverse(JournalEntry $entry, CarbonImmutable $date, string $reason, User $actor): JournalEntry
    {
        if ($entry->status !== JournalEntry::POSTED) {
            throw new InvalidAccountingOperation(__('accounting.errors.reverse_posted_only'));
        }

        if ($entry->reversal()->exists() || $entry->reversal_of_id !== null) {
            throw new InvalidAccountingOperation(__('accounting.errors.already_reversed'));
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidAccountingOperation(__('accounting.errors.reason_required'));
        }

        $lines = $entry->lines()->with('account')->get()
            ->map(fn (JournalEntryLine $l) => (new EntryLine($l->account->code, $l->debit, $l->credit, $l->label, $l->party_type, $l->party_id))->reversed())
            ->values()->all();
        /** @var list<EntryLine> $lines */
        $reversal = $this->post($entry->journal->code, $date, __('accounting.reversal_of', ['number' => $entry->number, 'reason' => trim($reason)]), $lines, $actor, reversalOf: $entry->id);

        $this->audit->record('accounting.entry_reversed', $entry, after: ['reversal' => $reversal->number], metadata: ['reason' => trim($reason)], actor: $actor);

        return $reversal;
    }

    public function account(string $code): ChartAccount
    {
        if (! isset($this->accounts[$code])) {
            /** @var ChartAccount|null $account */
            $account = ChartAccount::query()->where('code', $code)->first();

            if ($account === null || ! $account->active) {
                throw new InvalidAccountingOperation(__('accounting.errors.unknown_account', ['code' => $code]));
            }

            $this->accounts[$code] = $account;
        }

        return $this->accounts[$code];
    }

    /**
     * @param  list<EntryLine>  $lines
     */
    private function writeLines(JournalEntry $entry, array $lines, bool $requireBalance = true): void
    {
        if ($requireBalance) {
            $this->assertBalanced($lines);
        }

        foreach ($lines as $position => $line) {
            if (($line->debit > 0) === ($line->credit > 0) || $line->debit < 0 || $line->credit < 0) {
                throw new InvalidAccountingOperation(__('accounting.errors.line_amount', ['line' => $position + 1]));
            }

            $account = $this->account($line->accountCode);

            (new JournalEntryLine)->forceFill([
                'entry_id' => $entry->id,
                'position' => $position + 1,
                'account_id' => $account->id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'label' => mb_substr($line->label, 0, 255),
                'party_type' => $line->partyId !== null ? $line->partyType : null,
                'party_id' => $line->partyId,
            ])->save();
        }
    }

    /**
     * @param  list<EntryLine>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debit = array_sum(array_map(fn (EntryLine $l) => $l->debit, $lines));
        $credit = array_sum(array_map(fn (EntryLine $l) => $l->credit, $lines));

        if (count($lines) < 2 || $debit !== $credit || $debit === 0) {
            throw new InvalidAccountingOperation(__('accounting.errors.unbalanced', [
                'debit' => number_format($debit, 0, ',', ' '),
                'credit' => number_format($credit, 0, ',', ' '),
            ]));
        }
    }

    private function finalize(JournalEntry $entry, ?User $actor): JournalEntry
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->findOrFail($entry->period_id);
        $journal = AccountingJournal::query()->findOrFail($entry->journal_id);
        $year = $period->fiscalYear->starts_on->year;

        $entry->number = $this->sequences->formatted("journal:{$journal->code}:{$period->fiscal_year_id}", "{$journal->code}-{$year}", 6);
        $entry->status = JournalEntry::POSTED;
        $entry->posted_by = $actor?->id;
        $entry->posted_at = now()->toImmutable();
        $entry->save();

        return $entry;
    }

    /**
     * @return array{0: AccountingPeriod, 1: CarbonImmutable}
     */
    private function resolvePeriod(CarbonImmutable $date, bool $shift): array
    {
        $period = $this->calendar->periodFor($date);

        if ($period === null) {
            throw new InvalidAccountingOperation(__('accounting.errors.no_fiscal_year', ['date' => $date->format('d/m/Y')]));
        }

        if ($period->status === AccountingPeriod::OPEN) {
            return [$period, $date];
        }

        if (! $shift) {
            throw new InvalidAccountingOperation(__('accounting.errors.period_closed', ['period' => $period->starts_on->format('m/Y')]));
        }

        $next = $this->calendar->firstOpenPeriodFrom($date)
            ?? throw new InvalidAccountingOperation(__('accounting.errors.no_open_period'));

        return [$next, $next->starts_on];
    }

    private function journal(string $code): AccountingJournal
    {
        /** @var AccountingJournal|null $journal */
        $journal = AccountingJournal::query()->where('code', $code)->where('active', true)->first();

        return $journal ?? throw new InvalidAccountingOperation(__('accounting.errors.unknown_journal', ['code' => $code]));
    }
}
