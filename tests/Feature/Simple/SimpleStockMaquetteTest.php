<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * @return array<string, mixed>
     */
    private function bootstrapStock(): array
    {
        $setup = $this->createTenantWithMember();

        return $this->member($setup, 'warehouse_manager');
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    /**
     * Fixe le seuil de réapprovisionnement d'un article dans le dépôt courant.
     * La maquette 07a/07b distingue « low » (quantité ≤ seuil) de « enough ».
     */
    private function setReorderPoint(array $setup, string $productId, string $warehouseId, string $reorder): void
    {
        DB::table('product_warehouse_settings')->insert([
            'tenant_id' => $setup['tenant']->id,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'reorder_point' => $reorder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_products_carry_a_progress_ratio_and_a_status_label(): void
    {
        $s = $this->bootstrapStock();
        $low = $this->makeProduct($s, 'RIZ', 'U');
        $enough = $this->makeProduct($s, 'HUI', 'U');
        $this->move($s, 'initial', [[$s['warehouse']->id, $low->id, '4.000', '1000']]);
        $this->move($s, 'initial', [[$s['warehouse']->id, $enough->id, '10.000', '1000']]);
        $this->setReorderPoint($s, $low->id, $s['warehouse']->id, '10.000');
        $this->setReorderPoint($s, $enough->id, $s['warehouse']->id, '5.000');

        $this->act($s)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Stock')
                ->where('products', function (array $products) use ($low, $enough) {
                    $byId = [];
                    foreach ($products as $row) {
                        $byId[$row['id']] = $row;
                    }

                    $this->assertSame('low', $byId[$low->id]['status']);
                    $this->assertSame('enough', $byId[$enough->id]['status']);

                    // ratio entre 0 et 1, basé sur la quantité max du lot
                    // (utilisé par la barre de progression 07a).
                    $this->assertEqualsWithDelta(0.4, $byId[$low->id]['progress'], 0.001);
                    $this->assertEqualsWithDelta(1.0, $byId[$enough->id]['progress'], 0.001);
                    $this->assertSame(10.0, (float) $byId[$low->id]['progress_max']);

                    return true;
                })
                ->has('counters.enough', 1)
                ->where('counters.low', 1)
                ->where('counters.empty', 0));
    }

    public function test_withdrawal_reason_is_required_for_a_minus_action(): void
    {
        $s = $this->bootstrapStock();
        $product = $this->makeProduct($s, 'OEU', 'U');
        $this->move($s, 'initial', [[$s['warehouse']->id, $product->id, '10.000', '1000']]);

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

        $balance = $this->balance((string) $s['warehouse']->id, (string) $product->id);
        $this->assertEquals(9.0, (float) $balance['quantity']);
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
