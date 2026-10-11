<?php

namespace App\Domain\Accounting;

use App\Domain\Tenancy\TenantContext;
use App\Models\ChartAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * États comptables, calculés uniquement sur les écritures validées (docs/05) :
 * balance générale, grand livre, balances auxiliaires clients / fournisseurs.
 */
final class AccountingReports
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FiscalCalendar $calendar,
    ) {}

    /**
     * Balance générale : solde d'ouverture (avant $from), mouvements de la période, solde de clôture.
     *
     * @return list<array{account_id: string, code: string, name: string, class: int, opening: int, debit: int, credit: int, closing: int}>
     */
    public function trialBalance(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->postedLines($from)
            ->join('chart_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereDate('e.entry_date', '<=', $to->toDateString())
            ->groupBy('a.id', 'a.code', 'a.name', 'a.class')
            ->orderBy('a.code')
            ->selectRaw('a.id AS account_id, a.code, a.name, a.class,
                COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit ELSE 0 END), 0) AS opening,
                COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END), 0) AS debit,
                COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END), 0) AS credit',
                [$from->toDateString(), $from->toDateString(), $from->toDateString()])
            ->get();

        return array_values($rows->map(fn ($r) => [
            'account_id' => (string) $r->account_id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'class' => (int) $r->class,
            'opening' => (int) $r->opening,
            'debit' => (int) $r->debit,
            'credit' => (int) $r->credit,
            'closing' => (int) $r->opening + (int) $r->debit - (int) $r->credit,
        ])->all());
    }

    /**
     * Grand livre d'un compte : solde d'ouverture puis lignes avec solde progressif.
     *
     * @return array{opening: int, lines: list<array{id: string, date: string, journal: string, number: string, label: string, debit: int, credit: int, balance: int, entry_id: string}>, closing: int}
     */
    public function generalLedger(ChartAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $partyId = null): array
    {
        $base = $this->postedLines($from)->where('l.account_id', $account->id)
            ->when($partyId !== null, fn ($q) => $q->where('l.party_id', $partyId));

        $opening = (int) (clone $base)->whereDate('e.entry_date', '<', $from->toDateString())->sum(DB::raw('l.debit - l.credit'));
        $balance = $opening;

        $lines = (clone $base)->join('accounting_journals as j', 'j.id', '=', 'e.journal_id')
            ->whereDate('e.entry_date', '>=', $from->toDateString())
            ->whereDate('e.entry_date', '<=', $to->toDateString())
            ->orderBy('e.entry_date')->orderBy('e.number')->orderBy('l.position')
            ->limit(5000)
            ->get(['l.id', 'e.id as entry_id', 'e.entry_date', 'j.code as journal', 'e.number', 'l.label', 'l.debit', 'l.credit'])
            ->map(function ($r) use (&$balance) {
                $balance += (int) $r->debit - (int) $r->credit;

                return [
                    'id' => (string) $r->id,
                    'entry_id' => (string) $r->entry_id,
                    'date' => (string) $r->entry_date,
                    'journal' => (string) $r->journal,
                    'number' => (string) $r->number,
                    'label' => (string) $r->label,
                    'debit' => (int) $r->debit,
                    'credit' => (int) $r->credit,
                    'balance' => $balance,
                ];
            });

        return ['opening' => $opening, 'lines' => array_values($lines->all()), 'closing' => $balance];
    }

    /**
     * Balance auxiliaire : solde par client ou fournisseur à une date.
     *
     * @return list<array{party_id: string, code: string, name: string, debit: int, credit: int, balance: int}>
     */
    public function partyBalances(string $partyType, CarbonImmutable $to): array
    {
        $table = $partyType === 'customer' ? 'customers' : 'suppliers';

        $rows = $this->postedLines($to)
            ->join("{$table} as p", 'p.id', '=', 'l.party_id')
            ->where('l.party_type', $partyType)
            ->whereDate('e.entry_date', '<=', $to->toDateString())
            ->groupBy('p.id', 'p.code', 'p.name')
            ->orderBy('p.name')
            ->selectRaw('p.id AS party_id, p.code, p.name, SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->get();

        return array_values($rows->map(fn ($r) => [
            'party_id' => (string) $r->party_id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'debit' => (int) $r->debit,
            'credit' => (int) $r->credit,
            'balance' => (int) $r->debit - (int) $r->credit,
        ])->filter(fn (array $r) => $r['balance'] !== 0 || $r['debit'] !== 0)->all());
    }

    /**
     * Solde d'un compte à une date (écritures validées).
     */
    public function balance(string $accountId, CarbonImmutable $to): int
    {
        return (int) $this->postedLines($to)->where('l.account_id', $accountId)
            ->whereDate('e.entry_date', '<=', $to->toDateString())
            ->sum(DB::raw('l.debit - l.credit'));
    }

    /**
     * Lignes validées de l'exercice contenant $date : les soldes antérieurs y sont
     * repris par l'écriture d'à-nouveaux, jamais recomptés.
     */
    private function postedLines(CarbonImmutable $date): Builder
    {
        $yearStart = $this->calendar->periodFor($date)?->fiscalYear->starts_on;

        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.tenant_id', $this->context->tenantId())
            ->where('e.status', 'posted')
            ->when($yearStart !== null, fn (Builder $q) => $q->whereDate('e.entry_date', '>=', $yearStart?->toDateString()));
    }
}
