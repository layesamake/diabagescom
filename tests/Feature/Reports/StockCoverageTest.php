<?php

namespace Tests\Feature\Reports;

use App\Models\CashSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Couverture de stock et risque de rupture (Pilotage).
 *
 * Règles : fenêtre de 90 jours ; couverture calculée seulement avec au moins 14 jours
 * d'historique et 3 ventes (« Historique insuffisant » sinon) ; article à risque si
 * rupture, couverture sous 7 jours ou seuil de réapprovisionnement atteint.
 */
class StockCoverageTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private const TODAY = '2026-10-14 10:00:00';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function stockedOn(array $setup, string $date, string $sku, string $quantity, ?string $warehouseId = null): object
    {
        CarbonImmutable::setTestNow($date);
        $product = $this->makeProduct($setup, $sku, 'U', 1000);
        $this->move($setup, 'initial', [[$warehouseId ?? $setup['warehouse']->id, $product->id, $quantity, 1000]]);
        CarbonImmutable::setTestNow(self::TODAY);

        return $product;
    }

    /** @param list<string> $dates */
    private function soldOn(array $setup, array $dates, object $product, string $quantity): void
    {
        foreach ($dates as $date) {
            CarbonImmutable::setTestNow($date);
            $session = CashSession::query()->withoutGlobalScopes()->where('status', CashSession::OPEN)->first() ?? $this->openSession($setup);
            $this->sell($setup, $session, [[$product->id, $quantity]], [['cash', 2000 * (int) $quantity]]);
        }

        CarbonImmutable::setTestNow(self::TODAY);
    }

    private function coverage(array $setup, ?array $user = null): TestResponse
    {
        return $this->actingAs(($user ?? $setup)['user'])->get(route('reports.index'));
    }

    public function test_a_low_coverage_is_computed_from_net_demand_over_the_effective_window(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '19');
        // 3 ventes de 6 : 18 vendus sur 90 jours (0,2/jour), 1 restant → 5 jours.
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.window_days', 90)
            ->where('stockInsights.coverage.min_history_days', 14)
            ->where('stockInsights.coverage.min_sales', 3)
            ->where('stockInsights.coverage.risk_days', 7)
            ->where('stockInsights.coverage.counts.at_risk', 1)
            ->where('stockInsights.coverage.items.0.sku', 'RIZ')
            ->where('stockInsights.coverage.items.0.status', 'low')
            ->where('stockInsights.coverage.items.0.coverage_state', 'ok')
            ->where('stockInsights.coverage.items.0.coverage_days', 5)
            ->where('stockInsights.coverage.items.0.available', '1.000')
            ->where('stockInsights.coverage.items.0.sold', '18.000')
            ->where('stockInsights.coverage.items.0.daily_demand', '0.200')
            ->where('stockInsights.coverage.items.0.sales_count', 3)
            ->where('stockInsights.coverage.items.0.unit', 'U'));
    }

    public function test_supplier_lead_time_defaults_to_seven_days_and_is_exposed_for_reorder(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '19');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.items.0.sku', 'RIZ')
            ->where('stockInsights.coverage.items.0.lead_time_days', 7)
            ->where('stockInsights.coverage.items.0.reorder_date', '2026-10-21'));
    }

    public function test_a_comfortable_coverage_is_not_listed(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '100');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.at_risk', 0)
            ->where('stockInsights.coverage.items', []));
    }

    public function test_two_sales_are_not_enough_history_and_no_coverage_is_claimed(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '20');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-10-01 10:00:00'], $rice, '6');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.insufficient_history', 1)
            ->where('stockInsights.coverage.counts.at_risk', 0)
            ->where('stockInsights.coverage.items', []));
    }

    public function test_a_young_article_has_insufficient_history_even_with_three_sales(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-10-05 10:00:00', 'RIZ', '30');
        $this->soldOn($setup, ['2026-10-06 10:00:00', '2026-10-07 10:00:00', '2026-10-08 10:00:00'], $rice, '9');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.insufficient_history', 1)
            ->where('stockInsights.coverage.counts.at_risk', 0));
    }

    public function test_no_sales_never_gives_a_reassuring_coverage(): void
    {
        $setup = $this->createTenantWithMember();
        $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '40');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.no_sales', 1)
            ->where('stockInsights.coverage.counts.at_risk', 0)
            ->where('stockInsights.coverage.items', []));
    }

    public function test_an_out_of_stock_article_with_recent_sales_comes_first(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '19');
        $oil = $this->stockedOn($setup, '2026-06-01 10:00:00', 'HUILE', '6');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');
        $this->soldOn($setup, ['2026-09-02 10:00:00', '2026-09-16 10:00:00', '2026-10-02 10:00:00'], $oil, '2');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.at_risk', 2)
            ->where('stockInsights.coverage.counts.out_of_stock', 1)
            ->where('stockInsights.coverage.items.0.sku', 'HUILE')
            ->where('stockInsights.coverage.items.0.status', 'out')
            ->where('stockInsights.coverage.items.0.coverage_days', 0)
            ->where('stockInsights.coverage.items.1.sku', 'RIZ')
            ->where('stockInsights.coverage.items.1.status', 'low'));
    }

    public function test_reaching_the_reorder_point_flags_the_article_even_without_history(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '50');
        $this->actingAs($setup['user'])->post(route('stock.reorder_point', $rice), ['warehouse_id' => $setup['warehouse']->id, 'reorder_point' => '60'])->assertRedirect();

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.at_risk', 1)
            ->where('stockInsights.coverage.items.0.status', 'reorder')
            ->where('stockInsights.coverage.items.0.coverage_state', 'no_sales')
            ->where('stockInsights.coverage.items.0.coverage_days', null)
            ->where('stockInsights.coverage.items.0.below_reorder', true));
    }

    public function test_services_archived_articles_other_tenants_and_other_warehouses_are_excluded(): void
    {
        $setup = $this->createTenantWithMember();
        $foreign = $this->createTenantWithMember();
        $second = $this->makeWarehouse($setup, 'THS');
        $archived = $this->stockedOn($setup, '2026-06-01 10:00:00', 'ARCH', '19');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $archived, '6');
        DB::table('products')->where('id', $archived->id)->update(['archived_at' => '2026-10-02 10:00:00', 'active' => false]);
        $this->makeProduct($setup, 'SERV', 'U', 0, 'service');
        // Articles qui seraient « à risque » (seuil atteint) mais hors périmètre : autre entreprise, autre dépôt.
        $foreignProduct = $this->stockedOn($foreign, '2026-06-01 10:00:00', 'ETRANGER', '1');
        $this->actingAs($foreign['user'])->post(route('stock.reorder_point', $foreignProduct), ['warehouse_id' => $foreign['warehouse']->id, 'reorder_point' => '10'])->assertRedirect();
        $other = $this->stockedOn($setup, '2026-06-01 10:00:00', 'AUTRE', '1', $second->id);
        $this->actingAs($setup['user'])->post(route('stock.reorder_point', $other), ['warehouse_id' => $second->id, 'reorder_point' => '10'])->assertRedirect();

        // Direction affectée au seul dépôt principal.
        $management = $this->member($setup, 'management');

        $this->coverage($setup, $management)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.at_risk', 0)
            ->where('stockInsights.coverage.items', []));
    }

    public function test_the_coverage_is_hidden_from_users_who_cannot_see_the_global_stock(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '19');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');

        $manager = $this->member($setup, 'warehouse_manager');
        $this->coverage($setup, $manager)->assertInertia(fn (Assert $page) => $page->where('stockInsights', null));

        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'inventory.view')->delete();
        $this->coverage($setup, $management)->assertInertia(fn (Assert $page) => $page->where('stockInsights', null));
    }

    public function test_coverage_needs_no_margin_permission_and_exposes_no_cost(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '19');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '6');
        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'report.margin_view')->delete();

        $this->coverage($setup, $management)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.coverage.counts.at_risk', 1)
            ->where('stockInsights.coverage.items.0.sku', 'RIZ')
            ->missing('stockInsights.coverage.items.0.value')
            ->missing('stockInsights.coverage.items.0.cost'));
    }

    public function test_stock_risk_raises_a_priority_counting_articles_not_money(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->stockedOn($setup, '2026-06-01 10:00:00', 'RIZ', '6');
        $this->soldOn($setup, ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'], $rice, '2');

        $this->coverage($setup)->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->contains(
                fn ($p) => $p['type'] === 'stock_risk' && $p['level'] === 'urgent' && $p['count'] === 1 && $p['amount'] === 0,
            )));

        // Sans article à risque, aucune alerte inutile.
        $calm = $this->createTenantWithMember();
        $this->stockedOn($calm, '2026-06-01 10:00:00', 'RIZ', '40');
        $this->coverage($calm)->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->doesntContain(fn ($p) => $p['type'] === 'stock_risk')));
    }
}
