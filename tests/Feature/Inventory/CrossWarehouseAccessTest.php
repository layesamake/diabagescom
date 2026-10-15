<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\CountService;
use App\Domain\Inventory\TransferService;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Database\Factories\TenantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un gestionnaire affecté à un seul dépôt ne peut pas altérer les transferts
 * (dépôt source) ni les inventaires d'un dépôt qui ne lui est pas affecté.
 */
class CrossWarehouseAccessTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    /**
     * @return array{0: array<string, mixed>, 1: Warehouse, 2: User}
     */
    private function scenario(): array
    {
        $admin = $this->createTenantWithMember(plan: 'business');
        $other = $this->makeWarehouse($admin, 'THS');

        $manager = User::factory()->create();
        $membership = TenantFactory::addMember($admin['tenant'], $manager, 'warehouse_manager');
        DB::table('warehouse_memberships')->insert(['tenant_id' => $admin['tenant']->id, 'warehouse_id' => $admin['warehouse']->id, 'membership_id' => $membership->id]);

        return [$admin, $other, $manager];
    }

    public function test_a_destination_only_manager_cannot_cancel_a_transfer_of_another_warehouse(): void
    {
        [$admin, $other, $manager] = $this->scenario();
        $rice = $this->makeProduct($admin);
        $this->move($admin, 'initial', [[$other->id, $rice->id, '10', 1000]]);

        $this->enter($admin);
        $transfer = app(TransferService::class)->create($other->id, $admin['warehouse']->id, [['product_id' => $rice->id, 'quantity' => '2']], null, $admin['user']);

        $this->actingAs($manager)->post(route('transfers.cancel', $transfer))->assertForbidden();
        $this->assertSame(StockTransfer::DRAFT, StockTransfer::query()->withoutGlobalScopes()->findOrFail($transfer->id)->status);

        $this->actingAs($manager)->get(route('transfers.show', $transfer))->assertInertia(fn ($page) => $page->where('can.cancel', false));
    }

    public function test_a_manager_cannot_cancel_a_count_of_another_warehouse(): void
    {
        [$admin, $other, $manager] = $this->scenario();

        $this->enter($admin);
        $count = app(CountService::class)->create($other->id, null, $admin['user']);

        $this->actingAs($manager)->post(route('counts.cancel', $count))->assertForbidden();
        $this->assertSame(InventoryCount::DRAFT, InventoryCount::query()->withoutGlobalScopes()->findOrFail($count->id)->status);
    }

    public function test_a_manager_cannot_remove_a_line_of_a_count_of_another_warehouse(): void
    {
        [$admin, $other, $manager] = $this->scenario();
        $rice = $this->makeProduct($admin);

        $this->enter($admin);
        $counts = app(CountService::class);
        $count = $counts->create($other->id, null, $admin['user']);
        $line = $counts->setLine($count, $rice->id, '3', null);

        $this->actingAs($manager)->delete(route('counts.lines.destroy', [$count, $line]))->assertForbidden();
        $this->assertTrue(InventoryCountLine::query()->withoutGlobalScopes()->whereKey($line->id)->exists());
    }
}
