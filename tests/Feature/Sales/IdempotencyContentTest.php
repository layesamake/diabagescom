<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Cash\InvalidCashOperation;
use App\Domain\Sales\InvalidSalesOperation;
use App\Domain\Sales\PaymentService;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Une clé d'idempotence rejouée avec un autre contenu est refusée : le serveur ne
 * confirme jamais une opération qui n'est pas celle qu'il a enregistrée.
 */
class IdempotencyContentTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_a_pos_replay_with_another_basket_or_payment_is_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup);
        $key = (string) Str::uuid();

        $first = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]], key: $key);
        $replay = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]], key: $key);
        $this->assertSame($first->id, $replay->id, 'A faithful replay returns the recorded sale.');

        foreach ([
            [[[$rice->id, '10']], [['cash', 20000]]],
            [[[$rice->id, '1']], [['cash', 20000]]],
            [[[$rice->id, '1']], [['wave', 2000]]],
        ] as [$lines, $tenders]) {
            try {
                $this->sell($setup, $session, $lines, $tenders, key: $key);
                $this->fail('A replay with another content must be refused.');
            } catch (InvalidSalesOperation) {
            }
        }

        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame('19.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_a_payment_replay_with_another_amount_is_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $customer = $this->makeCustomer($setup, 100000, 30);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup);
        $invoice = $this->sell($setup, $session, [[$rice->id, '5']], [], $customer->id);
        $key = (string) Str::uuid();
        $payments = app(PaymentService::class);

        $first = $payments->receive($invoice, [['method' => 'cash', 'amount' => 1000]], $session->id, $setup['user'], $key);
        $replay = $payments->receive($invoice, [['method' => 'cash', 'amount' => 1000]], $session->id, $setup['user'], $key);
        $this->assertSame($first[0]->id, $replay[0]->id);

        $this->expectException(InvalidSalesOperation::class);

        try {
            $payments->receive($invoice, [['method' => 'cash', 'amount' => 8000]], $session->id, $setup['user'], $key);
        } finally {
            $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
            $this->assertSame(1000, SalesDocument::query()->withoutGlobalScopes()->findOrFail($invoice->id)->amount_paid);
        }
    }

    public function test_a_cash_movement_replay_with_another_content_is_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $session = $this->openSession($setup, 100000);
        $this->enter($setup);
        $cash = app(CashService::class);
        $key = (string) Str::uuid();

        $first = $cash->recordOther($session->id, 'income', 5000, 'Recette', 'Vente de cartons', $setup['user'], $key);
        $replay = $cash->recordOther($session->id, 'income', 5000, 'Recette', 'Vente de cartons', $setup['user'], $key);
        $this->assertSame($first->id, $replay->id);

        $before = CashMovement::query()->withoutGlobalScopes()->count();

        $this->expectException(InvalidCashOperation::class);

        try {
            $cash->recordOther($session->id, 'expense', 5000, 'Dépense', 'Transport', $setup['user'], $key);
        } finally {
            $this->assertSame($before, CashMovement::query()->withoutGlobalScopes()->count());
        }
    }
}
