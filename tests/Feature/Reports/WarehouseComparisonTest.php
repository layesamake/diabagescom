<?php

namespace Tests\Feature\Reports;

use App\Domain\Cash\CashService;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Models\CashRegister;
use App\Models\CashSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Comparaison des dépôts (Pilotage) : ventes, marge, stock et dettes par dépôt du périmètre,
 * avec les mêmes définitions et restrictions que les totaux.
 */
class WarehouseComparisonTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** @return array{0: array<string, mixed>, 1: object, 2: object} */
    private function twoWarehouses(): array
    {
        $setup = $this->createTenantWithMember();
        $b = $this->makeWarehouse($setup, 'THS');
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [
            [$setup['warehouse']->id, $rice->id, '10', 1000],
            [$b->id, $rice->id, '10', 1000],
        ]);
        $this->sellIn($setup, $setup['warehouse'], $rice, '1');
        $this->sellIn($setup, $b, $rice, '3');

        return [$setup, $b, $rice];
    }

    private function sellIn(array $setup, object $warehouse, object $product, string $quantity): void
    {
        $this->enter($setup);
        $session = CashSession::query()->withoutGlobalScopes()->where('status', CashSession::OPEN)
            ->whereHas('register', fn ($q) => $q->where('warehouse_id', $warehouse->id))->first()
            ?? app(CashService::class)->open(CashRegister::query()->where('warehouse_id', $warehouse->id)->firstOrFail(), 0, $setup['user']);
        $this->sell([...$setup, 'warehouse' => $warehouse], $session, [[$product->id, $quantity]], [['cash', 2000 * (int) $quantity]]);
    }

    private function page(array $setup, ?array $user = null, array $query = []): TestResponse
    {
        return $this->actingAs(($user ?? $setup)['user'])->get(route('reports.index', $query));
    }

    private function supplierInvoice(array $setup, object $warehouse, int $total): void
    {
        $this->enter($setup);
        $service = app(SupplierInvoiceService::class);
        $draft = $service->saveDraft(null, [
            'supplier_id' => $this->makeSupplier($setup)->id,
            'warehouse_id' => $warehouse->id,
            'supplier_reference' => 'REF-'.Str::random(8),
            'due_date' => '2026-12-31',
            'lines' => [['label' => 'Riz', 'quantity' => '1', 'unit_cost' => $total, 'tax_rate_basis_points' => 0]],
        ], $setup['user']);
        $service->validate($draft, $setup['user']);
    }

    public function test_each_warehouse_gets_its_sales_margin_stock_and_payables(): void
    {
        [$setup, $b] = $this->twoWarehouses();
        $this->supplierInvoice($setup, $b, 5000);

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->has('warehouseComparison', 2)
            ->where('warehouseComparison.0.id', $b->id)
            ->where('warehouseComparison.0.name', 'Dépôt THS')
            ->where('warehouseComparison.0.total', 6000)
            ->where('warehouseComparison.0.sales', 1)
            ->where('warehouseComparison.0.excl_tax', 5085)
            ->where('warehouseComparison.0.margin', 2085)
            ->where('warehouseComparison.0.margin_rate_basis_points', 4100)
            ->where('warehouseComparison.0.stock_value', 7000)
            ->where('warehouseComparison.0.payables', 5000)
            ->where('warehouseComparison.1.id', $setup['warehouse']->id)
            ->where('warehouseComparison.1.total', 2000)
            ->where('warehouseComparison.1.margin', 695)
            ->where('warehouseComparison.1.stock_value', 9000)
            ->where('warehouseComparison.1.payables', 0));
    }

    public function test_a_single_selected_warehouse_has_nothing_to_compare(): void
    {
        [$setup, $b] = $this->twoWarehouses();

        $this->page($setup, null, ['warehouse' => $b->id])->assertInertia(fn (Assert $page) => $page
            ->where('warehouseComparison', []));
    }

    public function test_only_warehouses_of_the_scope_are_compared(): void
    {
        [$setup] = $this->twoWarehouses();
        $foreign = $this->createTenantWithMember();
        $this->makeWarehouse($foreign, 'ETR');

        // Direction affectée au seul dépôt principal : un seul dépôt visible, rien à comparer.
        $management = $this->member($setup, 'management');
        $this->page($setup, $management)->assertInertia(fn (Assert $page) => $page
            ->where('warehouseComparison', []));

        $this->page($setup)->assertInertia(fn (Assert $page) => $page->has('warehouseComparison', 2));
    }

    public function test_cost_stock_and_payables_follow_their_own_permissions(): void
    {
        [$setup, $b] = $this->twoWarehouses();
        $this->supplierInvoice($setup, $b, 5000);

        $management = $this->member($setup, 'management');
        DB::table('warehouse_memberships')->insert(['tenant_id' => $setup['tenant']->id, 'warehouse_id' => $b->id, 'membership_id' => $management['membership']->id]);
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->whereIn('permission_code', ['report.margin_view', 'purchase.view'])->delete();

        $this->page($setup, $management)->assertInertia(fn (Assert $page) => $page
            ->has('warehouseComparison', 2)
            ->where('warehouseComparison.0.total', 6000)
            ->where('warehouseComparison.0.margin', null)
            ->where('warehouseComparison.0.margin_rate_basis_points', null)
            ->where('warehouseComparison.0.stock_value', null)
            ->where('warehouseComparison.0.payables', null));
    }

    public function test_the_comparison_sales_add_up_to_the_summary(): void
    {
        [$setup] = $this->twoWarehouses();

        $this->page($setup)->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 8000)
            ->where('warehouseComparison', fn ($rows) => collect($rows)->sum('total') === 8000));
    }
}
