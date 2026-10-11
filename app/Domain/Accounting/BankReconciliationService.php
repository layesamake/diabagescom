<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\BankReconciliation;
use App\Models\ChartAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rapprochement d'un compte de trésorerie (banque, Wave, Orange Money, caisse)
 * avec un relevé : on coche les lignes validées présentes sur le relevé ; le
 * rapprochement n'est terminé que si le solde rapproché égale le solde du relevé.
 * Une ligne n'est rapprochée qu'une fois (contrainte unique).
 */
final class BankReconciliationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
    ) {}

    public function start(ChartAccount $account, CarbonImmutable $statementDate, int $statementBalance, ?string $reference, User $actor): BankReconciliation
    {
        $this->access->assertCanWrite($this->context->tenant());

        if (! $account->reconcilable) {
            throw new InvalidAccountingOperation(__('accounting.errors.not_reconcilable'));
        }

        if (BankReconciliation::query()->where('account_id', $account->id)->where('status', 'draft')->exists()) {
            throw new InvalidAccountingOperation(__('accounting.errors.reconciliation_open'));
        }

        $reconciliation = BankReconciliation::query()->create([
            'account_id' => $account->id,
            'statement_date' => $statementDate->toDateString(),
            'statement_balance' => $statementBalance,
            'reference' => $reference,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('accounting.reconciliation_started', $reconciliation, after: ['account' => $account->code, 'balance' => $statementBalance], actor: $actor);

        return $reconciliation;
    }

    /**
     * Solde des lignes déjà rapprochées (tous rapprochements terminés) + lignes cochées.
     */
    public function reconciledBalance(BankReconciliation $reconciliation): int
    {
        return (int) DB::table('journal_entry_lines as l')
            ->join('bank_reconciliation_lines as r', 'r.line_id', '=', 'l.id')
            ->join('bank_reconciliations as b', 'b.id', '=', 'r.reconciliation_id')
            ->where('l.account_id', $reconciliation->account_id)
            ->where(fn ($q) => $q->where('b.status', 'completed')->orWhere('b.id', $reconciliation->id))
            ->sum(DB::raw('l.debit - l.credit'));
    }

    /**
     * @param  list<string>  $lineIds
     */
    public function toggle(BankReconciliation $reconciliation, array $lineIds, bool $checked): void
    {
        $this->access->assertCanWrite($this->context->tenant());
        $this->expectDraft($reconciliation);

        DB::transaction(function () use ($reconciliation, $lineIds, $checked): void {
            $valid = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
                ->where('l.tenant_id', $this->context->tenantId())->where('l.account_id', $reconciliation->account_id)
                ->where('e.status', 'posted')->whereDate('e.entry_date', '<=', $reconciliation->statement_date->toDateString())
                ->whereIn('l.id', $lineIds)->pluck('l.id')->all();

            if (! $checked) {
                DB::table('bank_reconciliation_lines')->where('reconciliation_id', $reconciliation->id)->whereIn('line_id', $valid)->delete();

                return;
            }

            foreach ($valid as $lineId) {
                DB::table('bank_reconciliation_lines')->insertOrIgnore([
                    'tenant_id' => $this->context->tenantId(), 'reconciliation_id' => $reconciliation->id, 'line_id' => $lineId,
                ]);
            }
        });
    }

    public function complete(BankReconciliation $reconciliation, User $actor): BankReconciliation
    {
        $this->access->assertCanWrite($this->context->tenant());
        $this->expectDraft($reconciliation);
        $difference = $reconciliation->statement_balance - $this->reconciledBalance($reconciliation);

        if ($difference !== 0) {
            throw new InvalidAccountingOperation(__('accounting.errors.reconciliation_difference', ['difference' => number_format($difference, 0, ',', ' ')]));
        }

        $reconciliation->status = 'completed';
        $reconciliation->completed_by = $actor->id;
        $reconciliation->completed_at = now()->toImmutable();
        $reconciliation->save();

        $this->audit->record('accounting.reconciliation_completed', $reconciliation, after: ['balance' => $reconciliation->statement_balance], actor: $actor);

        return $reconciliation;
    }

    private function expectDraft(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== 'draft') {
            throw new InvalidAccountingOperation(__('accounting.errors.reconciliation_done'));
        }
    }
}
