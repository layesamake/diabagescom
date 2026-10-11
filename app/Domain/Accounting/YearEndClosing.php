<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d'exercice : toutes les périodes clôturées, puis écriture d'à-nouveaux
 * dans l'exercice suivant (journal AN) reprenant les soldes des comptes de bilan
 * (classes 1 à 5) et le résultat net (classes 6 à 8) en 1301 / 1309.
 * Écritures de clôture à faire valider par l'expert-comptable.
 */
final class YearEndClosing
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly FiscalCalendar $calendar,
        private readonly Ledger $ledger,
        private readonly AccountingReports $reports,
        private readonly AuditLog $audit,
    ) {}

    public function close(FiscalYear $year, User $actor): FiscalYear
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($year, $actor): FiscalYear {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            if ($locked->status === FiscalYear::CLOSED) {
                return $locked;
            }

            if (AccountingPeriod::query()->where('fiscal_year_id', $locked->id)->where('status', '<>', AccountingPeriod::CLOSED)->exists()) {
                throw new InvalidAccountingOperation(__('accounting.errors.year_periods_open'));
            }

            if (JournalEntry::query()->where('status', JournalEntry::DRAFT)
                ->whereIn('period_id', AccountingPeriod::query()->select('id')->where('fiscal_year_id', $locked->id))->exists()) {
                throw new InvalidAccountingOperation(__('accounting.errors.year_drafts'));
            }

            $next = $this->calendar->ensureFiscalYear($locked->ends_on->addDay());
            $balances = $this->reports->trialBalance($locked->starts_on, $locked->ends_on);
            $lines = [];
            $result = 0;
            $label = __('accounting.labels.opening', ['year' => $locked->name]);

            foreach ($balances as $row) {
                if ($row['closing'] === 0) {
                    continue;
                }

                if ($row['class'] <= 5) {
                    $lines[] = $row['closing'] > 0
                        ? EntryLine::debit($row['code'], $row['closing'], $label)
                        : EntryLine::credit($row['code'], -$row['closing'], $label);
                } else {
                    $result += $row['closing']; // débit = charge, crédit = produit
                }
            }

            // Résultat : produits − charges (solde créditeur = bénéfice).
            if ($result < 0) {
                $lines[] = EntryLine::credit($this->mapping('result_profit'), -$result, __('accounting.labels.result', ['year' => $locked->name]));
            } elseif ($result > 0) {
                $lines[] = EntryLine::debit($this->mapping('result_loss'), $result, __('accounting.labels.result', ['year' => $locked->name]));
            }

            if (count($lines) >= 2) {
                /** @var list<EntryLine> $lines */
                $this->ledger->post('AN', $next->starts_on, $label, $lines, $actor,
                    source: ['type' => 'FiscalYear', 'id' => $locked->id, 'kind' => 'opening']);
            }

            $locked->status = FiscalYear::CLOSED;
            $locked->closed_by = $actor->id;
            $locked->closed_at = now()->toImmutable();
            $locked->save();

            $this->audit->record('accounting.fiscal_year_closed', $locked, after: ['year' => $locked->name, 'result' => -$result], actor: $actor);

            return $locked;
        });
    }

    private function mapping(string $key): string
    {
        $code = DB::table('account_mappings')->join('chart_accounts', 'chart_accounts.id', '=', 'account_mappings.account_id')
            ->where('account_mappings.tenant_id', $this->context->tenantId())->where('account_mappings.key', $key)
            ->value('chart_accounts.code');

        return is_string($code) ? $code : throw new InvalidAccountingOperation(__('accounting.errors.missing_mapping', ['key' => $key]));
    }
}
