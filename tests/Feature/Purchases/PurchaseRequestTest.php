<?php

namespace Tests\Feature\Purchases;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** @return array<string, mixed> */
    private function payload(array $setup, array $lines, array $extra = []): array
    {
        return ['warehouse_id' => $setup['warehouse']->id, 'needed_on' => now()->addDays(7)->toDateString(), 'notes' => 'Rupture prévue', 'lines' => $lines, ...$extra];
    }

    private function draft(array $author, array $lines): PurchaseRequest
    {
        $this->actingAs($author['user'])->post(route('purchases.requests.store'), $this->payload($author, $lines))->assertSessionHasNoErrors();

        return app(TenantContext::class)->runAs($author['tenant'], fn () => PurchaseRequest::query()->with('lines')->latest('created_at')->orderByDesc('id')->firstOrFail());
    }

    public function test_a_request_goes_from_draft_to_approval_to_a_supplier_order(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $oil = $this->makeProduct($setup, 'HUILE', 'U', 2500);
        $supplier = $this->makeSupplier($setup);
        $cashier = $this->member($setup, 'cashier');
        $purchaser = $this->member($setup, 'purchaser');

        $request = $this->draft($cashier, [['product_id' => $rice->id, 'quantity' => '20', 'note' => 'sacs de 25 kg'], ['product_id' => $oil->id, 'quantity' => '6']]);
        $this->assertSame('draft', $request->status);
        $this->assertNull($request->number);
        $this->assertCount(2, $request->lines);

        // Le brouillon se modifie, puis s'envoie.
        $this->actingAs($cashier['user'])->put(route('purchases.requests.update', $request->id), $this->payload($cashier, [['product_id' => $rice->id, 'quantity' => '30']], ['version' => $request->version]))->assertSessionHasNoErrors();
        $this->actingAs($cashier['user'])->post(route('purchases.requests.submit', $request->id))->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame('submitted', $request->status);
        $this->assertMatchesRegularExpression('/^DA-\d{4}-\d+$/', (string) $request->number);
        $this->assertCount(1, $request->lines);

        // Plus modifiable une fois envoyée ; le vendeur ne peut pas approuver.
        $this->actingAs($cashier['user'])->put(route('purchases.requests.update', $request->id), $this->payload($cashier, [['product_id' => $rice->id, 'quantity' => '99']]))->assertSessionHasErrors();
        $this->actingAs($cashier['user'])->post(route('purchases.requests.approve', $request->id))->assertForbidden();

        $this->actingAs($purchaser['user'])->post(route('purchases.requests.approve', $request->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->refresh()->status);

        // Les achats la transforment en commande fournisseur en brouillon, coûts préremplis.
        $this->actingAs($purchaser['user'])->post(route('purchases.requests.convert', $request->id), ['supplier_id' => $supplier->id])->assertRedirect();
        $request->refresh();
        $this->assertSame('ordered', $request->status);

        $order = app(TenantContext::class)->runAs($setup['tenant'], fn () => PurchaseOrder::query()->with('lines')->findOrFail($request->order_id));
        $this->assertSame('draft', $order->status);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame($setup['warehouse']->id, $order->warehouse_id);
        $this->assertStringContainsString((string) $request->number, (string) $order->notes);
        $line = $order->lines->sole();
        $this->assertSame($rice->id, $line->product_id);
        $this->assertSame('30.000', $line->quantity);
        $this->assertSame(1000, $line->unit_cost);

        // Rejouer ne crée pas une seconde commande.
        $this->actingAs($purchaser['user'])->post(route('purchases.requests.convert', $request->id), ['supplier_id' => $supplier->id])->assertRedirect();
        $this->assertSame(1, app(TenantContext::class)->runAs($setup['tenant'], fn () => PurchaseOrder::query()->count()));

        foreach (['draft_saved', 'submitted', 'approved', 'ordered'] as $step) {
            $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', "purchase.request_{$step}")->exists(), $step);
        }
    }

    public function test_rejection_needs_a_reason_and_blocks_the_order(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $supplier = $this->makeSupplier($setup);
        $cashier = $this->member($setup, 'cashier');
        $purchaser = $this->member($setup, 'purchaser');
        $request = $this->draft($cashier, [['product_id' => $rice->id, 'quantity' => '5']]);
        $this->actingAs($cashier['user'])->post(route('purchases.requests.submit', $request->id));

        $this->actingAs($purchaser['user'])->post(route('purchases.requests.reject', $request->id), [])->assertSessionHasErrors('reason');
        $this->actingAs($purchaser['user'])->post(route('purchases.requests.reject', $request->id), ['reason' => 'Budget épuisé'])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Budget épuisé', $request->decision_reason);

        $this->actingAs($purchaser['user'])->post(route('purchases.requests.convert', $request->id), ['supplier_id' => $supplier->id])->assertSessionHasErrors();
        $this->actingAs($purchaser['user'])->post(route('purchases.requests.approve', $request->id))->assertSessionHasErrors();
        $this->assertSame(0, app(TenantContext::class)->runAs($setup['tenant'], fn () => PurchaseOrder::query()->count()));
    }

    public function test_cancellation_rules(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $cashier = $this->member($setup, 'cashier');

        // Un brouillon s'annule sans motif.
        $draft = $this->draft($cashier, [['product_id' => $rice->id, 'quantity' => '5']]);
        $this->actingAs($cashier['user'])->post(route('purchases.requests.cancel', $draft->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->refresh()->status);

        // Envoyée : motif obligatoire.
        $sent = $this->draft($cashier, [['product_id' => $rice->id, 'quantity' => '5']]);
        $this->actingAs($cashier['user'])->post(route('purchases.requests.submit', $sent->id));
        $this->actingAs($cashier['user'])->post(route('purchases.requests.cancel', $sent->id))->assertSessionHasErrors();
        $this->actingAs($cashier['user'])->post(route('purchases.requests.cancel', $sent->id), ['reason' => 'Plus nécessaire'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $sent->refresh()->status);

        // Une demande annulée ne revient pas.
        $this->actingAs($cashier['user'])->post(route('purchases.requests.submit', $sent->id))->assertSessionHasErrors();
    }

    public function test_lines_are_validated(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U');
        $service = $this->makeProduct($setup, 'LIVRAISON', 'U', 500, 'service');
        $cashier = $this->member($setup, 'cashier');
        $store = fn (array $lines) => $this->actingAs($cashier['user'])->post(route('purchases.requests.store'), $this->payload($cashier, $lines));

        $store([['product_id' => $rice->id, 'quantity' => '0']])->assertSessionHasErrors();
        $store([['product_id' => $rice->id, 'quantity' => '2.5']])->assertSessionHasErrors(); // unité entière
        $store([['product_id' => $service->id, 'quantity' => '1']])->assertSessionHasErrors();
        $store([['product_id' => $rice->id, 'quantity' => '1'], ['product_id' => $rice->id, 'quantity' => '2']])->assertSessionHasErrors();
        $store([['product_id' => '00000000-0000-4000-8000-000000000000', 'quantity' => '1']])->assertSessionHasErrors();
        $this->actingAs($cashier['user'])->post(route('purchases.requests.store'), $this->payload($cashier, []))->assertSessionHasErrors('lines');
        $this->assertSame(0, app(TenantContext::class)->runAs($setup['tenant'], fn () => PurchaseRequest::query()->count()));
    }

    public function test_visibility_rights_and_company_isolation(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $alice = $this->member($setup, 'cashier');
        $bob = $this->member($setup, 'cashier');
        $purchaser = $this->member($setup, 'purchaser');
        $accountant = $this->member($setup, 'accountant');

        $request = $this->draft($alice, [['product_id' => $rice->id, 'quantity' => '5']]);
        $this->actingAs($alice['user'])->post(route('purchases.requests.submit', $request->id));

        // Chacun voit ses demandes ; les achats voient toutes celles de leurs dépôts.
        $this->actingAs($alice['user'])->get(route('purchases.requests.index'))->assertInertia(fn (Assert $p) => $p->has('requests.data', 1));
        $this->actingAs($bob['user'])->get(route('purchases.requests.index'))->assertInertia(fn (Assert $p) => $p->has('requests.data', 0));
        $this->actingAs($purchaser['user'])->get(route('purchases.requests.index', ['status' => 'pending']))->assertInertia(fn (Assert $p) => $p->has('requests.data', 1));

        $this->actingAs($bob['user'])->get(route('purchases.requests.show', $request->id))->assertNotFound();
        $this->actingAs($bob['user'])->post(route('purchases.requests.cancel', $request->id), ['reason' => 'Test'])->assertNotFound();
        $this->actingAs($alice['user'])->get(route('purchases.requests.show', $request->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('can.decide', false)->where('can.cancel', true));
        $this->actingAs($purchaser['user'])->get(route('purchases.requests.show', $request->id))
            ->assertInertia(fn (Assert $p) => $p->where('can.decide', true)->where('can.edit', false));

        // Sans le droit « demandes d'achat » (comptable) : refusé.
        $this->actingAs($accountant['user'])->get(route('purchases.requests.index'))->assertForbidden();
        $this->actingAs($accountant['user'])->post(route('purchases.requests.store'), $this->payload($setup, [['product_id' => $rice->id, 'quantity' => '1']]))->assertForbidden();

        // Une autre entreprise ne la voit pas.
        $other = $this->createTenantWithMember();
        $this->actingAs($other['user'])->get(route('purchases.requests.show', $request->id))->assertNotFound();
        $this->actingAs($other['user'])->post(route('purchases.requests.approve', $request->id))->assertNotFound();
    }

    public function test_an_approver_deciding_on_their_own_request_is_flagged_in_the_audit(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $request = $this->draft($setup, [['product_id' => $rice->id, 'quantity' => '5']]);
        $this->actingAs($setup['user'])->post(route('purchases.requests.submit', $request->id));
        $this->actingAs($setup['user'])->post(route('purchases.requests.approve', $request->id))->assertSessionHasNoErrors();

        $event = AuditEvent::query()->withoutGlobalScopes()->where('action', 'purchase.request_approved')->firstOrFail();
        $this->assertTrue((bool) ($event->metadata['self_decision'] ?? false));
    }
}
