<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — « Commander » (maquette 10a) : on choisit un fournisseur puis
 * des articles ; la commande est créée et confirmée (« en route ») par le même
 * service que le mode expert. Réservé à qui peut créer et confirmer (l'acheteur).
 */
class SimplePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    private function purchaser(): array
    {
        return $this->member($this->createTenantWithMember(), 'purchaser');
    }

    /** @return array<string, mixed> */
    private function payload(array $setup, string $supplierId, string $productId, array $line = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $setup['warehouse']->id,
            'supplier_id' => $supplierId,
            'lines' => [['product_id' => $productId, 'quantity' => '3', 'unit_cost' => 1500, ...$line]],
        ];
    }

    private function orders(array $setup): int
    {
        return PurchaseOrder::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count();
    }

    public function test_everything_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $setup = $this->purchaser();

        $this->act($setup)->get('/simple/achats/commander')->assertNotFound();
        $this->act($setup)->post('/simple/achats', [])->assertNotFound();
    }

    public function test_the_order_form_lists_suppliers_and_stockable_products_with_a_suggested_cost(): void
    {
        $setup = $this->purchaser();
        $supplier = $this->makeSupplier($setup);
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $service = $this->makeProduct($setup, 'SRV', 'U', 500, 'service');

        $this->act($setup)
            ->get('/simple/achats/commander')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/AchatCommande')
                ->where('warehouse.id', $setup['warehouse']->id)
                ->where('suppliers', fn ($list) => collect($list)->pluck('id')->contains($supplier->id))
                ->where('products', fn ($list) => collect($list)->firstWhere('id', $rice->id)['cost'] === 1000
                    && collect($list)->pluck('id')->doesntContain($service->id)));
    }

    public function test_a_purchaser_orders_and_the_order_is_confirmed_on_its_way(): void
    {
        $setup = $this->purchaser();
        $supplier = $this->makeSupplier($setup);
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);

        $this->act($setup)
            ->post('/simple/achats', $this->payload($setup, $supplier->id, $rice->id))
            ->assertRedirect('/simple/achats');

        $order = PurchaseOrder::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->firstOrFail();
        $this->assertSame(PurchaseOrder::CONFIRMED, $order->status);
        $this->assertNotNull($order->number);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame($setup['warehouse']->id, $order->warehouse_id);
        $this->assertGreaterThanOrEqual(4500, $order->total); // 3 × 1 500 F, taxes en plus.

        $line = $order->lines()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($rice->id, $line->product_id);
        $this->assertSame(1500, $line->unit_cost);
        $this->assertEquals(3, (float) $line->quantity);
    }

    public function test_the_new_order_shows_up_in_the_list_as_on_its_way(): void
    {
        $setup = $this->purchaser();
        $supplier = $this->makeSupplier($setup);
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->act($setup)->post('/simple/achats', $this->payload($setup, $supplier->id, $rice->id));

        $this->act($setup)
            ->get('/simple/achats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.order', true)
                ->has('open_orders', 1)
                ->where('open_orders.0.status', PurchaseOrder::CONFIRMED)
                ->where('open_orders.0.supplier', $supplier->name));
    }

    public function test_a_double_tap_with_the_same_key_creates_a_single_order(): void
    {
        $setup = $this->purchaser();
        $supplier = $this->makeSupplier($setup);
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $payload = $this->payload($setup, $supplier->id, $rice->id);

        $this->act($setup)->post('/simple/achats', $payload)->assertRedirect('/simple/achats');
        $this->act($setup)->post('/simple/achats', $payload)->assertRedirect('/simple/achats');

        $this->assertSame(1, $this->orders($setup));
    }

    public function test_invalid_lines_are_refused_and_nothing_is_created(): void
    {
        $setup = $this->purchaser();
        $supplier = $this->makeSupplier($setup);
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);

        foreach (['0', '1.5', '-2', 'abc', '10000000000'] as $quantity) {
            $this->act($setup)
                ->post('/simple/achats', $this->payload($setup, $supplier->id, $rice->id, ['quantity' => $quantity]))
                ->assertInvalid(['lines.0.quantity']);
        }

        $this->act($setup)
            ->post('/simple/achats', $this->payload($setup, $supplier->id, $rice->id, ['unit_cost' => -5]))
            ->assertInvalid(['lines.0.unit_cost']);
        $this->act($setup)
            ->post('/simple/achats', [...$this->payload($setup, $supplier->id, $rice->id), 'lines' => []])
            ->assertInvalid(['lines']);

        $twice = $this->payload($setup, $supplier->id, $rice->id);
        $twice['lines'][] = $twice['lines'][0];
        $this->act($setup)->post('/simple/achats', $twice)->assertInvalid(['lines.0.product_id']);

        $this->assertSame(0, $this->orders($setup));
    }

    public function test_a_role_that_cannot_order_gets_neither_the_form_nor_the_button(): void
    {
        $manager = $this->member($this->createTenantWithMember(), 'warehouse_manager');
        $supplier = $this->makeSupplier($manager);
        $rice = $this->makeProduct($manager, 'RIZ', 'U', 1000);

        $this->act($manager)->get('/simple/achats/commander')->assertForbidden();
        $this->act($manager)->post('/simple/achats', $this->payload($manager, $supplier->id, $rice->id))->assertForbidden();
        // Il voit la liste (et reçoit les commandes), mais sans « Commander ».
        $this->act($manager)->get('/simple/achats')->assertOk()->assertInertia(fn ($page) => $page->where('can.order', false));

        $this->assertSame(0, $this->orders($manager));
    }
}
