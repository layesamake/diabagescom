<?php

namespace Tests\Feature\Simple;

use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\PurchaseOrderService;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SimplePurchasesTest extends TestCase
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

    public function test_simple_purchases_lists_open_and_received_orders_for_the_accessible_warehouse(): void
    {
        $tenant = $this->createTenantWithMember();
        $purchaser = $this->member($tenant, 'purchaser');
        $product = $this->makeProduct($purchaser, 'RIZ', 'U');
        $supplier = $this->makeSupplier($purchaser);
        $this->enter($purchaser);

        $orders = app(PurchaseOrderService::class);
        $open = $orders->confirm($orders->saveDraft(null, [
            'warehouse_id' => $purchaser['warehouse']->id,
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '10',
                'unit_cost' => 1200,
            ]],
        ], $purchaser['user']), $purchaser['user']);

        $received = $orders->confirm($orders->saveDraft(null, [
            'warehouse_id' => $purchaser['warehouse']->id,
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '3',
                'unit_cost' => 1200,
            ]],
        ], $purchaser['user']), $purchaser['user']);
        $line = $received->lines()->firstOrFail();
        app(GoodsReceiptService::class)->receiveOrder(
            $received,
            [$line->id => '3'],
            null,
            null,
            $purchaser['user'],
            (string) Str::uuid(),
        );

        $this->act($purchaser)
            ->get('/simple/achats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Achats')
                ->has('open_orders', 1)
                ->where('open_orders.0.id', $open->id)
                ->where('open_orders.0.status', 'confirmed')
                ->has('received_orders', 1)
                ->where('received_orders.0.id', $received->id)
                ->where('received_orders.0.status', 'received')
                ->where('audio_code', 'ACH-01'));
    }
}
