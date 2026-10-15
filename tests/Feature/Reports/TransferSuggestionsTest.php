<?php

namespace Tests\Feature\Reports;

use App\Domain\Cash\CashService;
use App\Models\CashRegister;
use App\Models\CashSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Transferts suggérés entre dépôts (Pilotage) : un dépôt à risque de rupture (demande
 * fiable) reçoit l'excédent d'un autre dépôt du périmètre, sans jamais vider la source
 * de ce dont elle a elle-même besoin. Suggestion uniquement : rien n'est créé.
 */
class TransferSuggestionsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private const TODAY = '2026-10-14 10:00:00';

    private const DATES = ['2026-09-01 10:00:00', '2026-09-15 10:00:00', '2026-10-01 10:00:00'];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function stock(array $setup, object $warehouse, string $date, string $sku, string $quantity): object
    {
        CarbonImmutable::setTestNow($date);
        $product = $this->makeProduct($setup, $sku, 'U', 1000);
        $this->move($setup, 'initial', [[$warehouse->id, $product->id, $quantity, 1000]]);
        CarbonImmutable::setTestNow(self::TODAY);

        return $product;
    }

    private function sellIn(array $setup, object $warehouse, object $product, string $quantity): void
    {
        foreach (self::DATES as $date) {
            CarbonImmutable::setTestNow($date);
            $this->enter($setup);
            $session = CashSession::query()->withoutGlobalScopes()->where('status', CashSession::OPEN)
                ->whereHas('register', fn ($q) => $q->where('warehouse_id', $warehouse->id))->first()
                ?? app(CashService::class)->open(CashRegister::query()->where('warehouse_id', $warehouse->id)->firstOrFail(), 0, $setup['user']);
            $this->sell([...$setup, 'warehouse' => $warehouse], $session, [[$product->id, $quantity]], [['cash', 2000 * (int) $quantity]]);
        }

        CarbonImmutable::setTestNow(self::TODAY);
    }

    private function page(array $setup, ?array $user = null): TestResponse
    {
        return $this->actingAs(($user ?? $setup)['user'])->get(route('reports.index'));
    }

    public function test_surplus_of_an_idle_warehouse_covers_the_need_of_a_warehouse_running_low(): void
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->stock($setup, $setup['warehouse'], '2026-06-01 10:00:00', 'RIZ', '100');
        $this->makeProductStockIn($setup, $b, $rice);
        $this->sellIn($setup, $b, $rice, '6');

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.transfers.target_days', 30)
            ->where('stockInsights.transfers.items.0.sku', 'RIZ')
            ->where('stockInsights.transfers.items.0.source.id', $setup['warehouse']->id)
            ->where('stockInsights.transfers.items.0.destination.id', $b->id)
            ->where('stockInsights.transfers.items.0.quantity', '5.000')
            ->where('stockInsights.transfers.items.0.destination_available', '1.000')
            ->where('stockInsights.transfers.items.0.destination_coverage_days', 5)
            ->where('stockInsights.transfers.items.0.unit', 'U'));
    }

    public function test_the_source_keeps_what_its_own_demand_needs(): void
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->stock($setup, $setup['warehouse'], '2026-06-01 10:00:00', 'RIZ', '28');
        $this->makeProductStockIn($setup, $b, $rice);
        // A vend 18 sur 90 jours (0,2/jour → garde 6), il lui reste 10 : excédent 4 < besoin 5 de B.
        $this->sellIn($setup, $setup['warehouse'], $rice, '6');
        $this->sellIn($setup, $b, $rice, '6');

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.transfers.items.0.quantity', '4.000'));
    }

    public function test_no_suggestion_when_the_destination_is_comfortable(): void
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->stock($setup, $setup['warehouse'], '2026-06-01 10:00:00', 'RIZ', '100');
        $this->makeProductStockIn($setup, $b, $rice, '60');
        $this->sellIn($setup, $b, $rice, '6');

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.transfers.items', []));
    }

    public function test_a_source_without_reliable_history_is_never_drained(): void
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->stock($setup, $setup['warehouse'], '2026-10-10 10:00:00', 'RIZ', '100'); // reçu il y a 4 jours
        $this->makeProductStockIn($setup, $b, $rice);
        $this->sellIn($setup, $b, $rice, '6');

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.transfers.items', []));
    }

    public function test_only_warehouses_of_the_scope_are_considered_and_the_action_needs_transfer_rights(): void
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->stock($setup, $setup['warehouse'], '2026-06-01 10:00:00', 'RIZ', '100');
        $this->makeProductStockIn($setup, $b, $rice);
        $this->sellIn($setup, $b, $rice, '6');

        // Direction affectée au seul dépôt principal : le dépôt B n'est pas dans son périmètre.
        $management = $this->member($setup, 'management');
        $this->page($setup, $management)->assertInertia(fn (Assert $page) => $page
            ->where('stockInsights.transfers.items', [])
            ->where('can.transfer', false));

        $this->page($setup)->assertInertia(fn (Assert $page) => $page->where('can.transfer', true));
    }

    /**
     * Mouvement initial d'un article déjà créé dans un autre dépôt (stock du dépôt de destination).
     */
    private function makeProductStockIn(array $setup, object $warehouse, object $product, string $quantity = '19'): void
    {
        CarbonImmutable::setTestNow('2026-06-01 10:00:00');
        $this->move($setup, 'initial', [[$warehouse->id, $product->id, $quantity, 1000]]);
        CarbonImmutable::setTestNow(self::TODAY);
    }
}
