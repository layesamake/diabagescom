<?php

namespace Tests\Feature\Sales;

use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\InvalidSalesOperation;
use App\Domain\Sales\PaymentService;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Garde-fous contre les requêtes forgées ou rejouées avec une mauvaise clé.
 */
class SalesIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_a_credit_note_cannot_credit_the_same_line_twice_in_one_request(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);
        $line = $sale->lines()->firstOrFail();

        try {
            app(CreditNoteService::class)->create($sale, [
                ['line_id' => $line->id, 'quantity' => '3', 'restock' => true],
                ['line_id' => $line->id, 'quantity' => '3', 'restock' => true],
            ], 'Doublon', $setup['user'], (string) Str::uuid());
            $this->fail('A line must not be credited beyond the quantity sold.');
        } catch (InvalidSalesOperation) {
        }

        $this->assertSame(0, SalesDocument::query()->where('type', SalesDocument::CREDIT_NOTE)->count());
        $this->assertSame('7.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $this->assertSame(0, $sale->refresh()->amount_credited);

        // Deux fractions de la même ligne restent permises tant que le total ne dépasse pas la vente.
        $credit = app(CreditNoteService::class)->create($sale, [
            ['line_id' => $line->id, 'quantity' => '1', 'restock' => true],
            ['line_id' => $line->id, 'quantity' => '2', 'restock' => true],
        ], 'Retour complet', $setup['user'], (string) Str::uuid());

        $this->assertSame(6000, $credit->total);
        $this->assertSame('10.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_the_http_credit_note_endpoint_rejects_duplicated_lines(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);
        $line = $sale->lines()->firstOrFail();

        $this->actingAs($setup['user'])->post(route('sales.credit_notes.store', $sale), [
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Doublon',
            'lines' => [
                ['line_id' => $line->id, 'quantity' => '3', 'restock' => true],
                ['line_id' => $line->id, 'quantity' => '3', 'restock' => true],
            ],
        ])->assertSessionHasErrors('lines.0.line_id');

        $this->assertSame(0, SalesDocument::query()->withoutGlobalScopes()->where('type', SalesDocument::CREDIT_NOTE)->count());
    }

    public function test_an_idempotency_key_reused_on_another_invoice_is_rejected(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $first = $this->sell($setup, $session, [[$rice->id, '2']], [], $customer->id);
        $second = $this->sell($setup, $session, [[$rice->id, '2']], [], $customer->id);

        $key = (string) Str::uuid();
        app(PaymentService::class)->receive($first, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], $key);

        try {
            app(PaymentService::class)->receive($second, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], $key);
            $this->fail('A key already used for another invoice must be rejected.');
        } catch (InvalidSalesOperation) {
        }

        $this->assertSame(0, $second->refresh()->amount_paid);
        $this->assertSame('unpaid', $second->payment_status);
        $this->assertSame(1, Payment::query()->count());

        // Le rejeu légitime sur la même facture reste sans effet.
        app(PaymentService::class)->receive($first, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], $key);
        $this->assertSame(2000, $first->refresh()->amount_paid);

        $creditKey = (string) Str::uuid();
        $line = $first->lines()->firstOrFail();
        app(CreditNoteService::class)->create($first, [['line_id' => $line->id, 'quantity' => '1', 'restock' => false]], 'Geste', $setup['user'], $creditKey);

        $this->expectException(InvalidSalesOperation::class);
        app(CreditNoteService::class)->create($second, [['line_id' => $second->lines()->firstOrFail()->id, 'quantity' => '1', 'restock' => false]], 'Geste', $setup['user'], $creditKey);
    }

    public function test_a_point_of_sale_key_already_used_by_a_credit_note_is_rejected(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $sale = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);

        $key = (string) Str::uuid();
        app(CreditNoteService::class)->create($sale, [['line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '1', 'restock' => true]], 'Retour', $setup['user'], $key);

        $this->expectException(InvalidSalesOperation::class);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]], key: $key);
    }
}
