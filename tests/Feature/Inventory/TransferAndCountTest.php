<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\CountService;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InvalidStockOperation;
use App\Domain\Inventory\TransferService;
use App\Domain\Tenancy\TenantContext;
use App\Models\InventoryCount;
use App\Models\InventoryMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferAndCountTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    public function test_a_transfer_leaves_at_shipping_and_arrives_at_receipt(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $source = $setup['warehouse'];
        $destination = $this->makeWarehouse($setup, 'THS');
        $this->move($setup, 'initial', [[$source->id, $rice->id, '20', 1500]]);

        $this->enter($setup);
        $transfer = $this->transfers()->create($source->id, $destination->id, [['product_id' => $rice->id, 'quantity' => '8']], null, $setup['user']);
        $this->assertSame('20.000', $this->balance($source->id, $rice->id)['quantity'], 'A draft moves nothing.');

        $transfer = $this->transfers()->ship($transfer, $setup['user'], (string) Str::uuid());
        $this->assertSame(StockTransfer::IN_TRANSIT, $transfer->status);
        $this->assertSame('TR-'.now()->year.'-00001', $transfer->number);
        $this->assertSame('12.000', $this->balance($source->id, $rice->id)['quantity']);
        $this->assertSame('0.000', $this->balance($destination->id, $rice->id)['quantity'], 'In transit: not yet at destination.');

        $this->transfers()->receive($transfer, [], null, $setup['user'], (string) Str::uuid());
        $this->assertSame(['quantity' => '8.000', 'average_cost' => 1500], $this->balance($destination->id, $rice->id));
    }

    public function test_a_partial_receipt_needs_a_reason_and_the_missing_quantity_never_arrives(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $destination = $this->makeWarehouse($setup, 'THS');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $this->enter($setup);
        $transfer = $this->transfers()->ship(
            $this->transfers()->create($setup['warehouse']->id, $destination->id, [['product_id' => $rice->id, 'quantity' => '10']], null, $setup['user']),
            $setup['user'], (string) Str::uuid());

        try {
            $this->transfers()->receive($transfer, [$rice->id => '9'], null, $setup['user'], (string) Str::uuid());
            $this->fail('A reason should be required.');
        } catch (InvalidStockOperation) {
        }

        $this->transfers()->receive($transfer, [$rice->id => '9'], 'Un sac déchiré pendant le transport', $setup['user'], (string) Str::uuid());

        $this->assertSame('9.000', $this->balance($destination->id, $rice->id)['quantity']);
        $this->assertSame('0.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_shipping_without_stock_is_refused(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $destination = $this->makeWarehouse($setup, 'THS');
        $this->enter($setup);
        $transfer = $this->transfers()->create($setup['warehouse']->id, $destination->id, [['product_id' => $rice->id, 'quantity' => '1']], null, $setup['user']);

        $this->expectException(InsufficientStock::class);
        $this->transfers()->ship($transfer, $setup['user'], (string) Str::uuid());
    }

    public function test_a_shipped_transfer_cannot_be_cancelled_and_replays_are_harmless(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $destination = $this->makeWarehouse($setup, 'THS');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $this->enter($setup);
        $transfer = $this->transfers()->create($setup['warehouse']->id, $destination->id, [['product_id' => $rice->id, 'quantity' => '4']], null, $setup['user']);
        $key = (string) Str::uuid();

        $this->transfers()->ship($transfer, $setup['user'], $key);
        $this->transfers()->ship($transfer, $setup['user'], $key); // rejeu (double clic, réseau)
        $this->assertSame('6.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);

        $this->expectException(InvalidStockOperation::class);
        $this->transfers()->cancel($transfer, $setup['user']);
    }

    public function test_users_only_act_on_their_assigned_warehouses(): void
    {
        $admin = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($admin);
        $other = $this->makeWarehouse($admin, 'THS');
        $this->move($admin, 'initial', [[$other->id, $rice->id, '10', 1000]]);

        $manager = User::factory()->create();
        $membership = TenantFactory::addMember($admin['tenant'], $manager, 'warehouse_manager');
        DB::table('warehouse_memberships')->insert(['tenant_id' => $admin['tenant']->id, 'warehouse_id' => $admin['warehouse']->id, 'membership_id' => $membership->id]);

        // Non affecté au dépôt THS : l'écran comme l'API refusent.
        $this->actingAs($manager)->get(route('stock.index', ['warehouse' => $other->id]))->assertForbidden();
        $this->actingAs($manager)->post(route('stock.adjust'), [
            'mode' => 'adjustment', 'warehouse_id' => $other->id, 'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Test', 'lines' => [['product_id' => $rice->id, 'quantity' => '-1']],
        ])->assertForbidden();

        app(TenantContext::class)->set($admin['tenant'], $membership->load('role', 'tenant'));
        $this->expectException(AuthorizationException::class);
        $this->transfers()->create($other->id, $admin['warehouse']->id, [['product_id' => $rice->id, 'quantity' => '1']], null, $manager);
    }

    public function test_a_count_adjusts_variances_with_mandatory_reasons(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ');
        $oil = $this->makeProduct($setup, 'HUILE');
        $w = $setup['warehouse']->id;
        $this->move($setup, 'initial', [[$w, $rice->id, '10', 1000], [$w, $oil->id, '5', 500]]);

        $this->enter($setup);
        $counts = app(CountService::class);
        $count = $counts->create($w, null, $setup['user']);
        $counts->setLine($count, $rice->id, '8', null);
        $counts->setLine($count, $oil->id, '5', null);

        try {
            $counts->validate($count, $setup['user'], (string) Str::uuid());
            $this->fail('A reason should be required for the rice variance.');
        } catch (InvalidStockOperation $e) {
            $this->assertStringContainsString('Article RIZ', $e->getMessage());
        }

        $counts->setLine($count, $rice->id, '8', 'Deux sacs abîmés jetés');
        $validated = $counts->validate($count, $setup['user'], (string) Str::uuid());

        $this->assertSame(InventoryCount::VALIDATED, $validated->status);
        $this->assertSame('INV-'.now()->year.'-00001', $validated->number);
        $this->assertSame('8.000', $this->balance($w, $rice->id)['quantity']);
        // Aucun mouvement pour l'huile : pas d'écart.
        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->where('type', 'count_adjustment')->count());

        $this->expectException(InvalidStockOperation::class);
        $counts->setLine($validated, $rice->id, '7', 'Après coup');
    }

    public function test_stock_screen_lists_balances_and_low_stock_alerts(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '3', 1000]]);

        $this->actingAs($setup['user'])->post(route('stock.reorder_point', $rice), ['warehouse_id' => $setup['warehouse']->id, 'reorder_point' => '5'])->assertRedirect();

        $this->actingAs($setup['user'])->get(route('stock.index', ['low' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lowCount', 1)
                ->where('rows.data.0.sku', 'RIZ')
                ->where('rows.data.0.low', true)
                ->where('rows.data.0.value', 3000));

        $setup['membership']->forceFill(['ui_mode' => 'expert'])->save();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('alerts.low_stock', 1));
    }

    public function test_cashiers_do_not_see_stock_costs(): void
    {
        $admin = $this->createTenantWithMember();
        $rice = $this->makeProduct($admin);
        $this->move($admin, 'initial', [[$admin['warehouse']->id, $rice->id, '3', 1000]]);
        $cashier = User::factory()->create();
        $membership = TenantFactory::addMember($admin['tenant'], $cashier, 'warehouse_manager');
        DB::table('warehouse_memberships')->insert(['tenant_id' => $admin['tenant']->id, 'warehouse_id' => $admin['warehouse']->id, 'membership_id' => $membership->id]);

        $this->actingAs($cashier)->get(route('stock.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.data.0.average_cost', null)->where('rows.data.0.value', null));
    }
}
