<?php

namespace Tests\Feature\Reports;

use App\Models\CashSession;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Stock dormant (Pilotage) : article en stock, non vendu depuis N jours, et en stock
 * depuis au moins N jours (un article récent n'est pas « dormant »).
 */
class DormantStockTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private const TODAY = '2026-10-14 10:00:00';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function stockedOn(array $setup, string $date, string $sku, string $quantity = '10', int $cost = 1000, ?string $warehouseId = null): object
    {
        CarbonImmutable::setTestNow($date);
        $product = $this->makeProduct($setup, $sku, 'U', $cost);
        $this->move($setup, 'initial', [[$warehouseId ?? $setup['warehouse']->id, $product->id, $quantity, $cost]]);
        CarbonImmutable::setTestNow(self::TODAY);

        return $product;
    }

    private function soldOn(array $setup, string $date, object $product, string $quantity = '1'): void
    {
        CarbonImmutable::setTestNow($date);
        // Une seule session ouverte par caisse : on réutilise celle déjà ouverte.
        $session = CashSession::query()->withoutGlobalScopes()->where('status', CashSession::OPEN)->first() ?? $this->openSession($setup);
        $this->sell($setup, $session, [[$product->id, $quantity]], [['cash', 2000 * (int) $quantity]]);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    public function test_an_old_unsold_article_is_dormant_with_its_immobilised_value(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'RIZ', '10', 1000);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.days', 90)
            ->where('stockInsights.dormant.count', 1)
            ->where('stockInsights.dormant.value', 10000)
            ->where('stockInsights.dormant.items.0.sku', 'RIZ')
            ->where('stockInsights.dormant.items.0.quantity', '10.000')
            ->where('stockInsights.dormant.items.0.unit', 'U')
            ->where('stockInsights.dormant.items.0.last_sale', null)
            ->where('stockInsights.dormant.items.0.value', 10000));
    }

    public function test_a_recently_stocked_article_is_not_dormant_even_without_sales(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-10-01 10:00:00', 'NEUF');

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.count', 0)
            ->where('stockInsights.dormant.value', 0)
            ->where('stockInsights.dormant.items', []));
    }

    public function test_a_recent_sale_keeps_an_article_active_and_an_old_sale_does_not(): void
    {
        $setup = $this->createTenantWithMember();
        $active = $this->stockedOn($setup, '2026-05-01 10:00:00', 'ACTIF', '10');
        $old = $this->stockedOn($setup, '2026-05-01 10:00:00', 'ANCIEN', '10');
        $this->soldOn($setup, '2026-09-20 10:00:00', $active);
        $this->soldOn($setup, '2026-06-01 10:00:00', $old);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.count', 1)
            ->where('stockInsights.dormant.items.0.sku', 'ANCIEN')
            ->where('stockInsights.dormant.items.0.last_sale', '2026-06-01')
            ->where('stockInsights.dormant.items.0.quantity', '9.000'));
    }

    public function test_the_delay_is_configurable_and_invalid_values_fall_back_to_the_default(): void
    {
        $setup = $this->createTenantWithMember();
        $old = $this->stockedOn($setup, '2026-05-01 10:00:00', 'ANCIEN', '10');
        $this->soldOn($setup, '2026-06-01 10:00:00', $old);

        $this->actingAs($setup['user'])->get(route('reports.index', ['dormant_days' => 180]))->assertInertia(fn (Assert $page) => $page
            ->where('filters.dormant_days', 180)
            ->where('stockInsights.dormant.days', 180)
            ->where('stockInsights.dormant.count', 0));

        $this->actingAs($setup['user'])->get(route('reports.index', ['dormant_days' => 5]))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.days', 90)
            ->where('stockInsights.dormant.count', 1));
    }

    public function test_empty_archived_and_service_articles_are_excluded(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'VIDE', '5');
        $archived = $this->stockedOn($setup, '2026-05-01 10:00:00', 'ARCH', '5');
        $this->makeProduct($setup, 'SERV', 'U', 0, 'service');

        CarbonImmutable::setTestNow('2026-06-01 10:00:00');
        $empty = Product::query()->withoutGlobalScopes()->where('sku', 'VIDE')->firstOrFail();
        $this->move($setup, 'adjustment', [[$setup['warehouse']->id, $empty->id, '-5']], reason: 'Casse');
        DB::table('products')->where('id', $archived->id)->update(['archived_at' => '2026-06-02 10:00:00', 'active' => false]);
        CarbonImmutable::setTestNow(self::TODAY);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.count', 0));
    }

    public function test_the_immobilised_value_needs_the_margin_permission_but_quantities_do_not(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'RIZ', '10', 1000);
        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'report.margin_view')->delete();

        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.count', 1)
            ->where('stockInsights.dormant.value', null)
            ->where('stockInsights.dormant.items.0.quantity', '10.000')
            ->where('stockInsights.dormant.items.0.value', null));
    }

    public function test_nothing_is_sent_without_inventory_view_or_global_reports(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'RIZ');

        // report.view_own seulement : une demande calculée sur ses seules ventes serait faussée.
        $manager = $this->member($setup, 'warehouse_manager');
        $this->actingAs($manager['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights', null));

        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'inventory.view')->delete();
        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights', null));
    }

    public function test_other_tenants_and_unauthorized_warehouses_are_never_counted(): void
    {
        $setup = $this->createTenantWithMember();
        $foreign = $this->createTenantWithMember();
        $second = $this->makeWarehouse($setup, 'THS');
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'MIEN', '3');
        $this->stockedOn($foreign, '2026-05-01 10:00:00', 'ETRANGER', '99');
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'AUTRE', '50', 1000, $second->id);

        // Direction affectée au seul dépôt principal.
        $management = $this->member($setup, 'management');

        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.dormant.count', 1)
            ->where('stockInsights.dormant.items.0.sku', 'MIEN'));
    }

    public function test_dormant_stock_raises_a_calm_priority_only_when_the_value_is_known(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-05-01 10:00:00', 'RIZ', '10', 1000);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->contains(
                fn ($p) => $p['type'] === 'dormant_stock' && $p['level'] === 'watch' && $p['amount'] === 10000 && $p['count'] === 1
                    && ! str_contains(mb_strtolower($p['message']), 'perte'),
            )));

        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'report.margin_view')->delete();

        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->doesntContain(fn ($p) => $p['type'] === 'dormant_stock')));
    }
}
