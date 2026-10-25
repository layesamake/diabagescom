<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Cash\InvalidCashOperation;
use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Une session de caisse fermée ne reçoit plus aucun mouvement ni règlement : la règle est
 * tenue par PostgreSQL (filet de sécurité) et par le service (message métier clair).
 */
class ClosedCashSessionGuardTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function closedSession(array $setup): CashSession
    {
        $session = $this->openSession($setup, 10000);
        $this->enter($setup);

        return app(CashService::class)->close($session, 10000, null, $setup['user']);
    }

    /** @return array<string, mixed> */
    private function movementRow(array $setup, CashSession $session): array
    {
        return [
            'id' => (string) Str::uuid(), 'tenant_id' => $setup['tenant']->id, 'session_id' => $session->id,
            'type' => 'income', 'amount' => 75000, 'label' => 'Recette', 'reason' => 'Rattrapage',
            'idempotency_key' => (string) Str::uuid(), 'created_by' => $setup['user']->id, 'occurred_at' => now(),
        ];
    }

    public function test_postgres_refuses_a_cash_movement_in_a_closed_session(): void
    {
        $setup = $this->createTenantWithMember();
        $session = $this->closedSession($setup);

        try {
            DB::transaction(fn () => DB::table('cash_movements')->insert($this->movementRow($setup, $session)));
            $this->fail('A closed session must not accept movements.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('closed cash session', $e->getMessage());
        }

        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->where('session_id', $session->id)->count());
    }

    public function test_postgres_refuses_a_payment_attached_to_a_closed_session(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $open = $this->openSession($setup, 0);
        $sale = $this->sell($setup, $open, [[$rice->id, '1']], [['cash', 2000]]);
        $this->enter($setup);
        $closed = app(CashService::class)->close($open, 2000, null, $setup['user']);

        $payment = (array) DB::table('payments')->where('sales_document_id', $sale->id)->first();
        $payment['id'] = (string) Str::uuid();
        $payment['number'] = 'PC-TEST-1';
        $payment['idempotency_key'] = (string) Str::uuid();
        $payment['cash_session_id'] = $closed->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('closed cash session');
        DB::transaction(fn () => DB::table('payments')->insert($payment));
    }

    public function test_the_service_refuses_a_movement_on_a_closed_session_with_a_business_error(): void
    {
        $setup = $this->createTenantWithMember();
        $session = $this->closedSession($setup);

        $this->expectException(InvalidCashOperation::class);
        app(CashService::class)->recordMovement($session->refresh(), 'income', 75000, $setup['user'], (string) Str::uuid(), label: 'Recette', reason: 'Rattrapage');
    }

    public function test_an_open_session_still_accepts_movements(): void
    {
        $setup = $this->createTenantWithMember();
        $session = $this->openSession($setup, 10000);

        DB::transaction(fn () => DB::table('cash_movements')->insert($this->movementRow($setup, $session)));

        $this->assertSame(1, CashMovement::query()->withoutGlobalScopes()->where('session_id', $session->id)->count());
    }
}
