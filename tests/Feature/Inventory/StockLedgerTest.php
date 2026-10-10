<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InvalidStockOperation;
use App\Models\InventoryMovement;
use App\Models\StockOperation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Critère de fin d'EPIC-04 : tout changement de stock est expliqué par un
 * mouvement immuable unique, concurrent et testable.
 */
class StockLedgerTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    public function test_initial_stock_creates_one_movement_and_the_balance(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);

        $operation = $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '40', 15000]]);

        $movement = InventoryMovement::query()->withoutGlobalScopes()->sole();
        $this->assertSame($operation->id, $movement->operation_id);
        $this->assertSame('initial', $movement->type);
        $this->assertSame('40.000', $movement->quantity_delta);
        $this->assertSame(['quantity' => '40.000', 'average_cost' => 15000], $this->balance($setup['warehouse']->id, $rice->id));
    }

    public function test_receipts_update_the_weighted_average_cost(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $w = $setup['warehouse']->id;

        $this->move($setup, 'receipt', [[$w, $rice->id, '10', 100]]);
        $this->move($setup, 'receipt', [[$w, $rice->id, '30', 200]]);

        // (10×100 + 30×200) / 40 = 175
        $this->assertSame(175, $this->balance($w, $rice->id)['average_cost']);

        // Une sortie est valorisée au coût moyen et ne le modifie pas.
        $this->move($setup, 'sale', [[$w, $rice->id, '-5']]);
        $this->assertSame(175, InventoryMovement::query()->withoutGlobalScopes()->where('type', 'sale')->value('unit_cost'));
        $this->assertSame(['quantity' => '35.000', 'average_cost' => 175], $this->balance($w, $rice->id));
    }

    public function test_insufficient_stock_is_refused_and_nothing_is_written(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ');
        $oil = $this->makeProduct($setup, 'HUILE');
        $w = $setup['warehouse']->id;
        $this->move($setup, 'initial', [[$w, $rice->id, '10', 100], [$w, $oil->id, '2', 100]]);

        try {
            // Première ligne valide, seconde insuffisante : l'opération entière est refusée.
            $this->move($setup, 'sale', [[$w, $rice->id, '-3'], [$w, $oil->id, '-5']]);
            $this->fail('InsufficientStock expected.');
        } catch (InsufficientStock $e) {
            $this->assertStringContainsString('Article HUILE', $e->getMessage());
        }

        $this->assertSame('10.000', $this->balance($w, $rice->id)['quantity']);
        $this->assertSame(1, StockOperation::query()->withoutGlobalScopes()->count());
    }

    public function test_negative_stock_requires_an_explicit_reason(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $w = $setup['warehouse']->id;

        try {
            $this->move($setup, 'sale', [[$w, $rice->id, '-2']], allowNegative: true);
            $this->fail('A reason should be required.');
        } catch (InvalidStockOperation) {
        }

        $this->move($setup, 'sale', [[$w, $rice->id, '-2']], reason: 'Livraison urgente, stock en cours de réception', allowNegative: true);

        $this->assertSame('-2.000', $this->balance($w, $rice->id)['quantity']);
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.sale']);
    }

    public function test_replaying_an_operation_applies_it_only_once(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $w = $setup['warehouse']->id;
        $key = (string) Str::uuid();

        $first = $this->move($setup, 'initial', [[$w, $rice->id, '10', 100]], key: $key);
        $second = $this->move($setup, 'initial', [[$w, $rice->id, '10', 100]], key: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('10.000', $this->balance($w, $rice->id)['quantity']);
        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_movements_and_operations_are_append_only_in_database(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 100]]);

        foreach (['inventory_movements', 'stock_operations'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update(['created_at' => now()])); // point de sauvegarde
                $this->fail("{$table} should be append-only.");
            } catch (QueryException) {
            }

            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail("{$table} should be append-only.");
            } catch (QueryException) {
            }
        }

        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_whole_units_refuse_decimals_but_weights_accept_them(): void
    {
        $setup = $this->createTenantWithMember();
        $soap = $this->makeProduct($setup, 'SAVON', 'U');
        $sugar = $this->makeProduct($setup, 'SUCRE', 'KG');
        $w = $setup['warehouse']->id;

        $this->move($setup, 'initial', [[$w, $sugar->id, '12,5', 600]]);
        $this->assertSame('12.500', $this->balance($w, $sugar->id)['quantity']);

        $this->expectException(InvalidStockOperation::class);
        $this->move($setup, 'initial', [[$w, $soap->id, '1.5', 300]]);
    }

    public function test_services_have_no_stock(): void
    {
        $setup = $this->createTenantWithMember();
        $delivery = $this->makeProduct($setup, 'LIVRAISON', 'U', 0, 'service');

        $this->expectException(InvalidStockOperation::class);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $delivery->id, '1', 0]]);
    }

    public function test_another_companys_product_cannot_be_moved(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $foreign = $this->makeProduct($b, 'SECRET');

        $this->expectException(InvalidStockOperation::class);
        $this->move($a, 'initial', [[$a['warehouse']->id, $foreign->id, '1', 100]]);
    }

    public function test_every_balance_equals_the_sum_of_its_movements(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'KG');
        $w = $setup['warehouse']->id;

        $this->move($setup, 'initial', [[$w, $rice->id, '100', 500]]);
        foreach (['-12.5', '-0.25', '3', '-40'] as $delta) {
            $this->move($setup, $delta[0] === '-' ? 'sale' : 'customer_return', [[$w, $rice->id, $delta]]);
        }
        $this->move($setup, 'adjustment', [[$w, $rice->id, '-1.75']], reason: 'Casse');

        $sum = DB::table('inventory_movements')->where('warehouse_id', $w)->where('product_id', $rice->id)->sum('quantity_delta');
        $this->assertSame(bcadd((string) $sum, '0', 3), $this->balance($w, $rice->id)['quantity']);
        $this->assertSame('48.500', $this->balance($w, $rice->id)['quantity']);

        // Le solde après chaque mouvement est cohérent avec la chronologie.
        $last = InventoryMovement::query()->withoutGlobalScopes()->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame('48.500', $last->balance_after);
    }
}
