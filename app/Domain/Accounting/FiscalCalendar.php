<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Exercices et périodes mensuelles.
 *
 * - Une période ouverte reçoit des écritures ; verrouillée, elle n'en reçoit
 *   plus mais peut être rouverte ; clôturée, c'est définitif (docs/05).
 * - Une période ne se clôture qu'une fois les précédentes clôturées.
 */
final class FiscalCalendar
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Exercice de 12 mois commençant à $start (créé s'il n'existe pas).
     */
    public function ensureFiscalYear(CarbonImmutable $start): FiscalYear
    {
        $start = $start->startOfDay();
        $end = $start->addYear()->subDay();

        /** @var FiscalYear|null $existing */
        $existing = FiscalYear::query()->whereDate('starts_on', $start->toDateString())->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($start, $end): FiscalYear {
                $year = FiscalYear::query()->create([
                    'name' => $start->year === $end->year ? (string) $start->year : "{$start->year}-{$end->year}",
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                ]);

                for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
                    $year->periods()->create([
                        'starts_on' => $month->toDateString(),
                        'ends_on' => $month->addMonth()->subDay()->toDateString(),
                    ]);
                }

                return $year;
            });
        } catch (QueryException $e) {
            // Contrainte d'exclusion : chevauchement avec un exercice existant.
            if (str_contains($e->getMessage(), 'fiscal_years_no_overlap')) {
                throw new InvalidAccountingOperation(__('accounting.errors.fiscal_year_overlap'));
            }

            throw $e;
        }
    }

    public function periodFor(CarbonImmutable $date): ?AccountingPeriod
    {
        /** @var AccountingPeriod|null */
        return AccountingPeriod::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();
    }

    /**
     * Première période ouverte à partir de la date (report d'une écriture
     * automatique dont la période d'origine est verrouillée ou clôturée).
     */
    public function firstOpenPeriodFrom(CarbonImmutable $date): ?AccountingPeriod
    {
        /** @var AccountingPeriod|null */
        return AccountingPeriod::query()
            ->where('status', AccountingPeriod::OPEN)
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->orderBy('starts_on')
            ->first();
    }

    public function lock(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        return $this->transition($period, AccountingPeriod::OPEN, AccountingPeriod::LOCKED, $actor);
    }

    public function unlock(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        return $this->transition($period, AccountingPeriod::LOCKED, AccountingPeriod::OPEN, $actor);
    }

    public function close(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        $earlierOpen = AccountingPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->whereDate('starts_on', '<', $period->starts_on->toDateString())
            ->where('status', '<>', AccountingPeriod::CLOSED)
            ->exists();

        if ($earlierOpen) {
            throw new InvalidAccountingOperation(__('accounting.errors.close_order'));
        }

        return $this->transition($period, AccountingPeriod::LOCKED, AccountingPeriod::CLOSED, $actor);
    }

    private function transition(AccountingPeriod $period, string $from, string $to, User $actor): AccountingPeriod
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($period, $from, $to, $actor): AccountingPeriod {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status !== $from) {
                throw new InvalidAccountingOperation(__('accounting.errors.period_status', ['status' => __("accounting.period_status.{$locked->status}")]));
            }

            $locked->status = $to;

            if ($to === AccountingPeriod::CLOSED) {
                $locked->closed_by = $actor->id;
                $locked->closed_at = now()->toImmutable();
            }

            $locked->save();

            $this->audit->record("accounting.period_{$to}", $locked, after: ['period' => $locked->starts_on->format('Y-m')], actor: $actor);

            return $locked;
        });
    }
}
