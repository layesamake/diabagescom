<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SimpleModeStockTest extends TestCase
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

    public function test_the_simple_stock_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');

        $this->act($manager)->get('/simple/stock')->assertNotFound();
    }

    public function test_simple_stock_shows_balances_and_availability_counters(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');
        $rice = $this->makeProduct($manager, 'RIZ', 'U', 1500);

        $this->move($manager, 'initial', [[$manager['warehouse']->id, $rice->id, '12', 1500]]);

        $this->act($manager)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Stock')
                ->where('warehouse.id', $manager['warehouse']->id)
                ->where('counters.enough', 1)
                ->where('counters.low', 0)
                ->where('counters.empty', 0)
                ->where('products.0.id', $rice->id)
                ->where('products.0.quantity', '12.000')
                ->where('products.0.status', 'enough'));
    }

    public function test_simple_stock_gives_the_real_photo_address_of_an_article_that_has_one(): void
    {
        $tenant = $this->createTenantWithMember();
        $manager = $this->member($tenant, 'warehouse_manager');
        $withPhoto = $this->makeProduct($manager, 'RIZ', 'U', 1500);
        $withoutPhoto = $this->makeProduct($manager, 'SEL', 'U', 500);
        $withPhoto->forceFill(['image_path' => 'products/riz.webp'])->save();

        $this->act($manager)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products', function ($list) use ($withPhoto, $withoutPhoto): bool {
                    $products = collect($list)->keyBy('id');
                    $url = (string) $products[$withPhoto->id]['image_url'];

                    // Adresse signée vers l'image de CET article ; null pour celui qui n'en a pas.
                    return str_contains($url, "/products/{$withPhoto->id}/image")
                        && str_contains($url, 'signature=')
                        && $products[$withoutPhoto->id]['image_url'] === null;
                }));
    }
}
