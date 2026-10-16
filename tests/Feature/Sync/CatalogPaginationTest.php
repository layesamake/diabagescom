<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Catalogue hors ligne paginé : au-delà de la taille de page, aucun article ni client
 * n'est perdu, et le curseur ne peut être avancé qu'une fois toutes les pages reçues.
 */
class CatalogPaginationTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** @return array{0: array<string, mixed>, 1: mixed} */
    private function clientWithCatalog(int $products, int $customers = 0): array
    {
        $setup = $this->createTenantWithMember();

        for ($i = 1; $i <= $products; $i++) {
            $this->makeProduct($setup, sprintf('P%02d', $i));
        }

        for ($i = 1; $i <= $customers; $i++) {
            $this->makeCustomer($setup);
        }

        $device = (string) Str::uuid();
        $this->actingAs($setup['user'])->postJson(route('sync.devices.register'), ['device_key' => $device, 'label' => 'Tablette'])->assertOk();

        return [$setup, $this->actingAs($setup['user'])->withHeader('X-Device-Key', $device)];
    }

    public function test_pages_cover_every_product_exactly_once(): void
    {
        [$setup, $client] = $this->clientWithCatalog(5);
        $warehouse = $setup['warehouse']->id;

        $first = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2]))->assertOk();
        $first->assertJsonPath('has_more', true)->assertJsonCount(2, 'products');

        $second = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2, 'after_products' => $first->json('next_after_products')]))->assertOk();
        $second->assertJsonPath('has_more', true)->assertJsonCount(2, 'products');

        $third = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2, 'after_products' => $second->json('next_after_products')]))->assertOk();
        $third->assertJsonPath('has_more', false)->assertJsonCount(1, 'products');

        $ids = array_merge(array_column($first->json('products'), 'id'), array_column($second->json('products'), 'id'), array_column($third->json('products'), 'id'));
        $this->assertCount(5, $ids);
        $this->assertCount(5, array_unique($ids), 'No product is repeated or lost across pages.');
    }

    public function test_products_and_customers_are_paged_independently(): void
    {
        [$setup, $client] = $this->clientWithCatalog(1, 3);
        $warehouse = $setup['warehouse']->id;

        $first = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2]))->assertOk();
        $first->assertJsonCount(1, 'products')->assertJsonCount(2, 'customers')->assertJsonPath('has_more', true);

        $second = $client->getJson(route('sync.catalog', [
            'warehouse' => $warehouse, 'limit' => 2,
            'after_products' => $first->json('next_after_products'), 'after_customers' => $first->json('next_after_customers'),
        ]))->assertOk();
        $second->assertJsonCount(0, 'products')->assertJsonCount(1, 'customers')->assertJsonPath('has_more', false);
    }

    public function test_stock_and_sessions_are_only_sent_with_the_first_page(): void
    {
        [$setup, $client] = $this->clientWithCatalog(3);
        $rice = $this->makeProduct($setup, 'STK');
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '7', 1000]]);
        $warehouse = $setup['warehouse']->id;

        $first = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2]))->assertOk();
        $first->assertJsonPath('first_page', true)->assertJsonPath('stock.'.$rice->id, '7');

        $second = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'limit' => 2, 'after_products' => $first->json('next_after_products')]))->assertOk();
        $second->assertJsonPath('first_page', false)->assertJsonPath('stock', null)->assertJsonPath('sessions', null);
    }

    public function test_an_incremental_request_is_paged_the_same_way(): void
    {
        [$setup, $client] = $this->clientWithCatalog(3);
        $warehouse = $setup['warehouse']->id;
        $this->travel(1)->minutes();
        $full = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse]))->assertOk();
        $full->assertJsonPath('has_more', false)->assertJsonCount(3, 'products');

        // Tout a changé depuis un curseur ancien : trois articles à renvoyer, en deux pages.
        $delta = $client->getJson(route('sync.catalog', ['warehouse' => $warehouse, 'since' => now()->subDay()->toIso8601String(), 'limit' => 2]))->assertOk();
        $delta->assertJsonPath('full', false)->assertJsonPath('has_more', true)->assertJsonCount(2, 'products');
    }

    public function test_the_page_size_is_bounded(): void
    {
        [$setup, $client] = $this->clientWithCatalog(1);

        $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id, 'limit' => 0]))->assertUnprocessable();
        $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id, 'limit' => 5001]))->assertUnprocessable();
        $client->getJson(route('sync.catalog', ['warehouse' => $setup['warehouse']->id, 'after_products' => 'pas-un-uuid']))->assertUnprocessable();
    }
}
