<?php

namespace Tests\Feature\Sync;

use App\Domain\Cash\CashService;
use App\Domain\Catalog\ProductService;
use App\Models\InventoryMovement;
use App\Models\OfflineDevice;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\SyncCommand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function registerDevice(array $setup): string
    {
        $key = (string) Str::uuid();
        $this->actingAs($setup['user'])->postJson(route('sync.devices.register'), ['device_key' => $key, 'label' => 'Tablette caisse'])->assertOk();

        return $key;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function saleCommand(array $setup, string $sessionId, string $productId, string $quantity = '1', array $overrides = []): array
    {
        return [
            'command_id' => (string) Str::uuid(),
            'type' => 'sale',
            'created_at' => now()->toIso8601String(),
            'payload' => array_replace([
                'warehouse_id' => $setup['warehouse']->id,
                'cash_session_id' => $sessionId,
                'customer_id' => null,
                'lines' => [['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => 2000, 'discount' => 0]],
                'tenders' => [['method' => 'cash', 'amount' => 2000 * (int) $quantity]],
            ], $overrides),
        ];
    }

    private function push(array $setup, string $deviceKey, array ...$commands): array
    {
        return $this->actingAs($setup['user'])
            ->withHeader('X-Device-Key', $deviceKey)
            ->postJson(route('sync.commands'), ['commands' => $commands])
            ->assertOk()
            ->json('results');
    }

    public function test_an_offline_sale_is_accepted_once_and_replays_return_the_same_result(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $device = $this->registerDevice($setup);
        $command = $this->saleCommand($setup, $session->id, $rice->id, '2');

        [$first] = $this->push($setup, $device, $command);
        // Coupure pendant l'envoi : l'appareil renvoie la même commande.
        [$replay] = $this->push($setup, $device, $command);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('FA-'.now()->year.'-00001', $first['result']['number']);
        $this->assertSame($first, $replay);
        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->where('type', 'sale')->count());
        $this->assertSame('3.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);

        // Même identifiant, contenu modifié : refusé, jamais retraité.
        $tampered = $command;
        $tampered['payload']['lines'][0]['quantity'] = '1';
        [$result] = $this->push($setup, $device, $tampered);
        $this->assertSame('hash_mismatch', $result['error']['code']);
        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_an_offline_sale_with_a_payment_is_refused_without_the_cash_receive_permission(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $seller = $this->member($setup, 'cashier');
        DB::table('role_permissions')->where('role_id', $seller['membership']->role_id)->where('permission_code', 'cash.receive')->delete();
        $device = $this->registerDevice($seller);

        [$result] = $this->push($seller, $device, $this->saleCommand($seller, $session->id, $rice->id));

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame(0, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame('5.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
    }

    public function test_a_sale_interrupted_online_is_completed_by_the_queued_command_without_duplicate(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $device = $this->registerDevice($setup);
        $command = $this->saleCommand($setup, $session->id, $rice->id);

        // La vente en ligne a abouti mais la réponse s'est perdue : l'écran l'a mise en file avec la même clé.
        $this->sell($setup, $session, [[$rice->id, '1', 2000]], [['cash', 2000]], key: $command['command_id']);
        [$result] = $this->push($setup, $device, $command);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_two_devices_selling_the_last_item_get_an_explicit_conflict(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '1', 1000]]);
        $session = $this->openSession($setup);
        $tablet = $this->registerDevice($setup);
        $phone = $this->registerDevice($setup);

        [$first] = $this->push($setup, $tablet, $this->saleCommand($setup, $session->id, $rice->id));
        [$second] = $this->push($setup, $phone, $this->saleCommand($setup, $session->id, $rice->id));

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('insufficient_stock', $second['error']['code']);
        $this->assertStringContainsString('Stock insuffisant', $second['error']['message']);
        $this->assertSame('0.000', $this->balance($setup['warehouse']->id, $rice->id)['quantity']);
        $this->assertSame(1, SalesDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_changed_prices_and_archived_products_go_to_review_without_loss(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $oil = $this->makeProduct($setup, 'HUILE');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000], [$setup['warehouse']->id, $oil->id, '5', 1000]]);
        $cashier = $this->member($setup, 'cashier');
        $session = $this->openSession($cashier);
        $device = $this->registerDevice($cashier);

        DB::table('products')->where('id', $rice->id)->update(['sale_price' => 2500]);
        DB::table('products')->where('id', $oil->id)->update(['archived_at' => now()]);

        [$price] = $this->push($cashier, $device, $this->saleCommand($cashier, $session->id, $rice->id));
        [$archived] = $this->push($cashier, $device, $this->saleCommand($cashier, $session->id, $oil->id));

        $this->assertSame('requires_review', $price['status']);
        $this->assertSame('price_changed', $price['error']['code']);
        $this->assertSame(2000, $price['result']['issues'][0]['captured']);
        $this->assertSame(2500, $price['result']['issues'][0]['current']);
        $this->assertSame('product_archived', $archived['error']['code']);
        $this->assertSame(0, SalesDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, SyncCommand::query()->withoutGlobalScopes()->where('status', 'requires_review')->count());
    }

    public function test_a_closed_register_or_a_revoked_device_is_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $device = $this->registerDevice($setup);
        $this->enter($setup);
        app(CashService::class)->close($session, 10000, null, $setup['user']);

        [$closed] = $this->push($setup, $device, $this->saleCommand($setup, $session->id, $rice->id));
        $this->assertSame('rejected', $closed['status']);
        $this->assertStringContainsString('caisse est fermée', $closed['error']['message']);

        $record = OfflineDevice::query()->withoutGlobalScopes()->where('device_key', $device)->firstOrFail();
        $this->actingAs($setup['user'])->post(route('sync.devices.revoke', $record->id))->assertRedirect();

        $this->actingAs($setup['user'])->withHeader('X-Device-Key', $device)
            ->postJson(route('sync.commands'), ['commands' => [$this->saleCommand($setup, $session->id, $rice->id)]])
            ->assertForbidden();
        $this->actingAs($setup['user'])->withHeader('X-Device-Key', $device)
            ->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id]))
            ->assertForbidden();
        // Une clé révoquée ne peut pas être réenregistrée.
        $this->actingAs($setup['user'])->postJson(route('sync.devices.register'), ['device_key' => $device])->assertForbidden();
    }

    public function test_another_user_cannot_use_my_device_and_commands_are_append_only(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '5', 1000]]);
        $session = $this->openSession($setup);
        $device = $this->registerDevice($setup);
        $this->push($setup, $device, $this->saleCommand($setup, $session->id, $rice->id));

        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->withHeader('X-Device-Key', $device)
            ->postJson(route('sync.commands'), ['commands' => [$this->saleCommand($setup, $session->id, $rice->id)]])
            ->assertForbidden();

        $this->expectException(QueryException::class);
        DB::table('sync_commands')->update(['status' => 'rejected']);
    }

    public function test_the_catalog_is_incremental_and_limited_to_accessible_warehouses(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $rice = $this->makeProduct($setup);
        $other = $this->makeWarehouse($setup, 'THS');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '7', 1000]]);
        $device = $this->registerDevice($setup);
        $client = $this->actingAs($setup['user'])->withHeader('X-Device-Key', $device);
        // Le curseur recouvre volontairement les 5 dernières secondes : on vieillit les données.
        $this->travel(1)->minutes();

        $full = $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id]))->assertOk();
        $full->assertJsonPath('full', true)->assertJsonPath('products.0.sku', 'RIZ')->assertJsonPath('stock.'.$rice->id, '7');

        $delta = $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id, 'since' => $full->json('cursor')]))->assertOk();
        $this->assertSame([], $delta->json('products'), 'Nothing changed since the cursor.');

        $this->enter($setup);
        app(ProductService::class)->addBarcode($rice, '6111234567895');
        $delta = $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id, 'since' => $full->json('cursor')]))->assertOk();
        $this->assertSame(['6111234567895'], $delta->json('products.0.barcodes'));

        $cashier = $this->member($setup, 'cashier');
        $cashierDevice = $this->registerDevice($cashier);
        $this->actingAs($cashier['user'])->withHeader('X-Device-Key', $cashierDevice)
            ->getJson(route('sync.catalog', ['warehouse' => $other->id]))->assertForbidden();
    }
}
