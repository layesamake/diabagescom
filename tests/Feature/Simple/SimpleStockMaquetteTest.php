<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 5, alignement de l'écran Stock sur la maquette 07a/07b.
 *
 * La maquette 07a demande un ratio « progress » (0..1) par article pour
 * animer la barre de progression visuelle ; la maquette 07b ouvre une feuille
 * « Pourquoi ? » avec trois motifs de retrait. Le contrôleur doit donc fournir
 * le ratio et accepter un motif sur l'action de retrait.
 */
class SimpleStockMaquetteTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    /**
     * @return array{tenant: mixed, user: mixed, warehouse: Warehouse, unit: Unit}
     */
    private function bootstrapStock(): array
    {
        $setup = $this->createTenantWithMember();

        $v = $this->member($setup, 'cashier');
        $warehouse = $v['warehouse'];
        $unit = Unit::query()->firstOrCreate(['code' => 'kg'], ['name' => 'Kilogramme']);

        return $setup + ['warehouse' => $warehouse, 'unit' => $unit];
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_products_carry_a_progress_ratio_and_a_status_label(): void
    {
        $s = $this->bootstrapStock();
        $product = Product::create([
            'tenant_id' => $s['tenant']->id,
            'name' => 'Riz parfumé',
            'sku' => 'RIZ',
            'unit_id' => $s['unit']->id,
            'type' => 'stockable',
        ]);
        StockMovement::create([
            'tenant_id' => $s['tenant']->id,
            'warehouse_id' => $s['warehouse']->id,
            'product_id' => $product->id,
            'quantity' => '4.000',
            'reason' => 'initial',
        ]);

        $this->act($s)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Stock')
                ->where('products.0.id', $product->id)
                ->where('products.0.name', 'Riz parfumé')
                ->where('products.0.status', 'low')
                // ratio entre 0 et 1 (utilisé par la barre de progression 07a)
                ->where('products.0.progress', 0.4)
                ->where('products.0.progress_max', 10)
                ->has('counters.enough', 0)
                ->where('counters.low', 1)
                ->where('counters.empty', 0));
    }

    public function test_withdrawal_reason_is_required_for_a_minus_action(): void
    {
        $s = $this->bootstrapStock();
        $product = Product::create([
            'tenant_id' => $s['tenant']->id,
            'name' => 'Œufs',
            'sku' => 'OEU',
            'unit_id' => $s['unit']->id,
            'type' => 'stockable',
        ]);
        StockMovement::create([
            'tenant_id' => $s['tenant']->id,
            'warehouse_id' => $s['warehouse']->id,
            'product_id' => $product->id,
            'quantity' => '10.000',
            'reason' => 'initial',
        ]);

        // Action « − » sans motif : 422
        $this->act($s)
            ->post('/simple/stock/ajuster', [
                'product_id' => $product->id,
                'delta' => '-1',
            ])
            ->assertStatus(422);

        // Avec motif « abîmé » : accepté, stock ramené à 9
        $this->act($s)
            ->post('/simple/stock/ajuster', [
                'product_id' => $product->id,
                'delta' => '-1',
                'reason' => 'abîmé',
            ])
            ->assertRedirect();

        $balance = StockMovement::query()
            ->where('product_id', $product->id)
            ->sum('quantity');
        $this->assertEquals(9.0, (float) $balance);
    }

    public function test_supported_withdrawal_reasons_match_the_maquette(): void
    {
        $s = $this->bootstrapStock();

        $this->act($s)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('withdrawal_reasons', 3)
                ->where('withdrawal_reasons.0', 'abîmé')
                ->where('withdrawal_reasons.1', 'mort')
                ->where('withdrawal_reasons.2', 'perdu'));
    }
}
