<?php

namespace App\Domain\Cash;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sessions de caisse (docs/02 §8) :
 * - ouverture avec fond de caisse ; une seule session ouverte par caisse ;
 * - tout mouvement d'espèces passe par une session ouverte (une caisse fermée ne
 *   reçoit rien) et est append-only ;
 * - une sortie ne peut pas dépasser les espèces présentes dans le tiroir ;
 * - fermeture : montant compté, écart calculé, justification obligatoire si écart.
 */
final class CashService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    public function createRegister(string $warehouseId, string $name, User $actor): CashRegister
    {
        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($warehouseId);

        return DB::transaction(function () use ($warehouse, $name, $actor): CashRegister {
            if (CashRegister::query()->where('warehouse_id', $warehouse->id)->whereRaw('lower(name) = lower(?)', [trim($name)])->exists()) {
                throw new InvalidCashOperation(__('cash.errors.register_exists'));
            }

            $register = CashRegister::query()->create(['warehouse_id' => $warehouse->id, 'name' => trim($name)]);
            $this->audit->record('cash.register_created', $register, after: ['name' => $register->name, 'warehouse' => $warehouse->code], actor: $actor);

            return $register;
        });
    }

    public function open(CashRegister $register, int $openingAmount, User $actor): CashSession
    {
        $this->access->assertCanWrite($this->context->tenant());
        $this->stockAccess->warehouse($register->warehouse_id);

        if (! $register->active) {
            throw new InvalidCashOperation(__('cash.errors.register_inactive'));
        }

        if ($openingAmount < 0) {
            throw new InvalidCashOperation(__('cash.errors.negative_amount'));
        }

        try {
            return DB::transaction(function () use ($register, $openingAmount, $actor): CashSession {
                $session = new CashSession;
                $session->register_id = $register->id;
                $session->opening_amount = $openingAmount;
                $session->opened_by = $actor->id;
                $session->opened_at = now()->toImmutable();
                $session->save();

                $this->audit->record('cash.session_opened', $session, after: ['register' => $register->name, 'opening_amount' => $openingAmount], actor: $actor);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidCashOperation(__('cash.errors.already_open', ['register' => $register->name]));
        }
    }

    /**
     * Verrouille une session ouverte (dans la transaction appelante) et vérifie
     * qu'elle appartient au dépôt de l'opération et que l'acteur peut l'utiliser
     * (sa propre session, ou cash.view_all pour un superviseur).
     */
    public function lockOpenSession(string $sessionId, ?string $warehouseId, User $actor): CashSession
    {
        /** @var CashSession|null $session */
        $session = CashSession::query()->with('register')->lockForUpdate()->find($sessionId);

        if ($session === null || $session->status !== CashSession::OPEN) {
            throw new InvalidCashOperation(__('cash.errors.session_closed'));
        }

        $this->stockAccess->warehouse($session->register->warehouse_id);
        $this->assertCanOperate($session, $actor);

        if ($warehouseId !== null && $session->register->warehouse_id !== $warehouseId) {
            throw new InvalidCashOperation(__('cash.errors.other_warehouse'));
        }

        return $session;
    }

    /**
     * Un caissier n'opère que sur la session qu'il a ouverte ; cash.view_all
     * (superviseur, administrateur) permet d'agir sur celle d'un collègue.
     */
    private function assertCanOperate(CashSession $session, User $actor): void
    {
        if ($session->opened_by !== $actor->id && ! Gate::forUser($actor)->allows('cash.view_all')) {
            throw new AuthorizationException(__('cash.errors.not_your_session'));
        }
    }

    /**
     * Espèces théoriquement présentes : fond de caisse + mouvements.
     */
    public function drawerBalance(CashSession $session): int
    {
        return $session->opening_amount + (int) CashMovement::query()->where('session_id', $session->id)->sum('amount');
    }

    /**
     * Mouvement d'espèces dans une session déjà verrouillée.
     */
    public function recordMovement(CashSession $session, string $type, int $amount, User $actor, string $idempotencyKey, ?Payment $payment = null, ?string $label = null, ?string $reason = null, ?string $number = null): CashMovement
    {
        if ($amount < 0 && $this->drawerBalance($session) + $amount < 0) {
            throw new InvalidCashOperation(__('cash.errors.insufficient_cash', [
                'available' => number_format($this->drawerBalance($session), 0, ',', ' '),
            ]));
        }

        $movement = new CashMovement;
        $movement->forceFill([
            'session_id' => $session->id,
            'type' => $type,
            'amount' => $amount,
            'payment_id' => $payment?->id,
            'label' => $label,
            'reason' => $reason,
            'number' => $number,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ])->save();

        return $movement;
    }

    /**
     * Dépense (sortie) ou recette diverse (entrée) : libellé et motif obligatoires.
     */
    public function recordOther(string $sessionId, string $type, int $amount, string $label, string $reason, User $actor, string $idempotencyKey): CashMovement
    {
        $this->access->assertCanWrite($this->context->tenant());

        if (! in_array($type, ['expense', 'income'], true)) {
            throw new InvalidCashOperation(__('cash.errors.invalid_type'));
        }

        if ($amount <= 0) {
            throw new InvalidCashOperation(__('cash.errors.positive_amount'));
        }

        if (mb_strlen(trim($reason)) < 3 || trim($label) === '') {
            throw new InvalidCashOperation(__('cash.errors.reason_required'));
        }

        try {
            return DB::transaction(function () use ($sessionId, $type, $amount, $label, $reason, $actor, $idempotencyKey): CashMovement {
                $existing = CashMovement::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }

                $session = $this->lockOpenSession($sessionId, null, $actor);
                $number = $this->sequences->document("cash:{$type}", $type === 'expense' ? 'DP' : 'RD');
                $movement = $this->recordMovement($session, $type, $type === 'expense' ? -$amount : $amount, $actor, $idempotencyKey,
                    label: trim($label), reason: trim($reason), number: $number);

                $this->audit->record("cash.{$type}", $movement, after: ['number' => $number, 'amount' => $amount, 'label' => trim($label)], metadata: ['reason' => trim($reason)], actor: $actor);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $e) {
            return CashMovement::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }

    public function close(CashSession $session, int $countedAmount, ?string $note, User $actor): CashSession
    {
        $this->access->assertCanWrite($this->context->tenant());

        if ($countedAmount < 0) {
            throw new InvalidCashOperation(__('cash.errors.negative_amount'));
        }

        return DB::transaction(function () use ($session, $countedAmount, $note, $actor): CashSession {
            /** @var CashSession $locked */
            $locked = CashSession::query()->with('register')->lockForUpdate()->findOrFail($session->id);
            $this->stockAccess->warehouse($locked->register->warehouse_id);
            $this->assertCanOperate($locked, $actor);

            if ($locked->status !== CashSession::OPEN) {
                throw new InvalidCashOperation(__('cash.errors.session_closed'));
            }

            $expected = $this->drawerBalance($locked);
            $difference = $countedAmount - $expected;

            if ($difference !== 0 && mb_strlen(trim((string) $note)) < 3) {
                throw new InvalidCashOperation(__('cash.errors.difference_reason', ['difference' => number_format($difference, 0, ',', ' ')]));
            }

            $locked->status = CashSession::CLOSED;
            $locked->closed_by = $actor->id;
            $locked->closed_at = now()->toImmutable();
            $locked->expected_amount = $expected;
            $locked->counted_amount = $countedAmount;
            $locked->difference = $difference;
            $locked->closing_note = filled($note) ? trim((string) $note) : null;
            $locked->version++;
            $locked->save();

            $this->audit->record('cash.session_closed', $locked,
                after: ['expected' => $expected, 'counted' => $countedAmount, 'difference' => $difference],
                metadata: array_filter(['reason' => $locked->closing_note]), actor: $actor);

            return $locked;
        });
    }

    /**
     * Récapitulatif d'une session : encaissements par mode, sorties, solde du tiroir.
     *
     * @return array{by_method: array<string, int>, expenses: int, incomes: int, refunds: int, supplier_payments: int, drawer: int}
     */
    public function summary(CashSession $session): array
    {
        $byMethod = Payment::query()->where('cash_session_id', $session->id)
            ->selectRaw("method, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS total")
            ->groupBy('method')->orderBy('method')->pluck('total', 'method')->map(fn ($v) => (int) $v)->all();
        $byType = CashMovement::query()->where('session_id', $session->id)
            ->selectRaw('type, SUM(amount) AS total')->groupBy('type')->pluck('total', 'type')->map(fn ($v) => (int) $v);

        return [
            'by_method' => $byMethod,
            'expenses' => -(int) ($byType['expense'] ?? 0),
            'incomes' => (int) ($byType['income'] ?? 0),
            'refunds' => -(int) ($byType['customer_refund'] ?? 0),
            'supplier_payments' => -(int) ($byType['supplier_payment'] ?? 0),
            'drawer' => $this->drawerBalance($session),
        ];
    }
}
