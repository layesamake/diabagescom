<?php

namespace Tests\Feature\Reports;

use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentService;
use App\Models\AuditEvent;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_revenue_margin_and_scope_follow_permissions(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000); // prix 2 000 TTC
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $session = $this->openSession($setup);
        $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);
        // Gestionnaire de dépôt : report.view_own sans droit sur les coûts (le Vendeur n'a aucun accès au Pilotage).
        $cashier = $this->member($setup, 'warehouse_manager');
        $this->sell($cashier, $session, [[$rice->id, '1']], [['wave', 2000]]);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/Sales')
            ->where('summary.total', 8000)
            ->where('summary.sales', 2)
            ->where('summary.average', 4000)
            ->where('summary.cost', 4000)
            ->where('summary.margin', 6780 - 4000) // HT 6 780 − coût 4 000
            ->where('summary.stock_value', 16000)
            ->has('bySeller', 2)
            ->has('byMethod', 2));

        // Périmètre « propre » : ses ventes seulement, sans coûts ni marge.
        $this->actingAs($cashier['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2000)
            ->where('summary.margin', null)
            ->where('can.all', false));

        $csv = $this->actingAs($setup['user'])->get(route('reports.index', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString(';2;8000', $csv->streamedContent());
        $this->assertTrue(AuditEvent::query()->where('action', 'report.exported')->exists());
    }

    public function test_a_seller_cannot_query_pilotage_in_any_format(): void
    {
        $setup = $this->createTenantWithMember();
        $seller = $this->member($setup, 'cashier');

        $this->actingAs($seller['user'])->get(route('reports.index'))->assertForbidden();
        $this->actingAs($seller['user'])->get(route('reports.index', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($seller['user'])->get(route('reports.index', ['format' => 'xlsx']))->assertForbidden();
        $this->actingAs($seller['user'])->get(route('reports.index', ['format' => 'pdf']))->assertForbidden();
    }

    public function test_own_scope_shows_nothing_to_a_user_without_sales(): void
    {
        $setup = $this->createTenantWithMember();
        $purchaser = $this->member($setup, 'purchaser');

        // Responsable achats : report.view_own accordé, donc accès limité à ses propres ventes (aucune).
        $this->actingAs($purchaser['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page->where('summary.total', 0));
    }

    public function test_pilotage_compares_equivalent_periods_and_explains_overdue_receivables(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, 100000, 0);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);

        CarbonImmutable::setTestNow('2026-10-13 10:00:00');
        $session = $this->openSession($setup);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 1000]], $customer->id);

        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 4000]], $customer->id);

        $this->actingAs($setup['user'])->get(route('reports.index', [
            'from' => '2026-10-14',
            'to' => '2026-10-14',
            'warehouse' => $setup['warehouse']->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('reports/Sales')
            ->where('period.from', '2026-10-14')
            ->where('period.to', '2026-10-14')
            ->where('period.previous_from', '2026-10-13')
            ->where('period.previous_to', '2026-10-13')
            ->where('period.today', '2026-10-14')
            ->where('period.timezone', 'Africa/Dakar')
            ->where('summary.total', 4000)
            ->where('summary.margin', 1390)
            ->where('comparison.total.value', 2000)
            ->where('comparison.total.change', 2000)
            ->where('comparison.total.change_basis_points', 10000)
            ->where('byDayMargin.0.day', '2026-10-14')
            ->where('byDayMargin.0.margin', 1390)
            ->where('receivables.total', 1000)
            ->where('receivables.overdue', 1000)
            ->where('receivables.aging.1_7.amount', 1000)
            ->where('receivables.aging.1_7.count', 1)
            ->where('products.0.sku', 'RIZ')
            ->where('products.0.unit', 'U')
            ->where('products.0.quantity', '2.000')
            ->where('products.0.revenue_excl_tax', 3390)
            ->where('products.0.margin', 1390)
            ->where('products.0.stock', '17.000')
            ->has('priorities'));

        CarbonImmutable::setTestNow();
    }

    public function test_own_scope_hides_cost_metadata_without_margin_permission(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $cashier = $this->member($setup, 'warehouse_manager');
        $this->sell($cashier, $this->openSession($setup), [[$rice->id, '1']], [['cash', 2000]]);

        $this->actingAs($cashier['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.0.cost', null)
            ->where('products.0.cost_complete', null)
            ->where('can.catalog', true)
            ->where('can.sales', true));
    }

    public function test_credit_note_is_attributed_to_the_source_invoice_seller(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $cashier = $this->member($setup, 'warehouse_manager');
        $session = $this->openSession($setup);

        CarbonImmutable::setTestNow('2026-10-13 10:00:00');
        $sale = $this->sell($cashier, $session, [[$rice->id, '1']], [['cash', 2000]]);
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        app(CreditNoteService::class)->create($sale, [[
            'line_id' => $sale->lines()->value('id'),
            'quantity' => '1',
            'restock' => false,
        ]], 'Geste commercial', $setup['user'], (string) Str::uuid());

        $this->actingAs($cashier['user'])->get(route('reports.index', [
            'from' => '2026-10-14',
            'to' => '2026-10-14',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', -2000)
            ->where('byDay.0.total', -2000));

        CarbonImmutable::setTestNow();
    }

    public function test_payment_breakdown_uses_payment_date_not_invoice_date(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, 100000, 30);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);

        CarbonImmutable::setTestNow('2026-10-13 10:00:00');
        $sale = $this->sell($setup, $session, [[$rice->id, '1']], [], $customer->id);
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        app(PaymentService::class)->receive($sale, [['method' => 'transfer', 'amount' => 2000]], null, $setup['user'], (string) Str::uuid());

        $this->actingAs($setup['user'])->get(route('reports.index', [
            'from' => '2026-10-14',
            'to' => '2026-10-14',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 0)
            ->where('byMethod.0.method', 'transfer')
            ->where('byMethod.0.total', 2000));

        CarbonImmutable::setTestNow();
    }

    public function test_credit_without_restock_keeps_missing_cost_warning(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);

        CarbonImmutable::setTestNow('2026-10-13 10:00:00');
        $sale = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
        DB::statement('ALTER TABLE sales_document_lines DISABLE TRIGGER sales_document_lines_frozen');

        try {
            DB::table('sales_document_lines')->where('document_id', $sale->id)->update(['unit_cost' => null]);
        } finally {
            DB::statement('ALTER TABLE sales_document_lines ENABLE TRIGGER sales_document_lines_frozen');
        }

        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        app(CreditNoteService::class)->create($sale->refresh(), [[
            'line_id' => $sale->lines()->value('id'),
            'quantity' => '1',
            'restock' => false,
        ]], 'Geste commercial', $setup['user'], (string) Str::uuid());

        $this->actingAs($setup['user'])->get(route('reports.index', [
            'from' => '2026-10-14',
            'to' => '2026-10-14',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.missing_cost_revenue', 1695)
            ->where('summary.cost_coverage_basis_points', 0)
            ->where('priorities.1.type', 'missing_cost')
            ->where('priorities.1.count', 1));

        CarbonImmutable::setTestNow();
    }

    public function test_payables_require_purchase_view_even_with_global_reports(): void
    {
        $setup = $this->createTenantWithMember();
        $management = $this->member($setup, 'management');
        Role::system('tenant', 'management')->permissions()->detach('purchase.view');

        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.payables', null)
            ->where('can.purchases', false));
    }

    public function test_negative_margin_priority_scans_beyond_displayed_products(): void
    {
        $setup = $this->createTenantWithMember();
        $movements = [];
        $lines = [];

        for ($i = 1; $i <= 25; $i++) {
            $product = $this->makeProduct($setup, sprintf('P%02d', $i), 'U', 100);
            $movements[] = [$setup['warehouse']->id, $product->id, '2', 100];
            $lines[] = [$product->id, '1'];
        }

        $loss = $this->makeProduct($setup, 'LOSS', 'U', 2000);
        $movements[] = [$setup['warehouse']->id, $loss->id, '2', 2000];
        $lines[] = [$loss->id, '1', 1000];
        $this->move($setup, 'initial', $movements);
        $this->sell($setup, $this->openSession($setup), $lines, [['cash', 6000]]);

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->has('products', 25)
            ->where('priorities.0.type', 'negative_margin')
            ->where('priorities.0.count', 1));
    }
}
