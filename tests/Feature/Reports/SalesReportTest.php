<?php

namespace Tests\Feature\Reports;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $cashier = $this->member($setup, 'cashier');
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

        // Caissier : ses ventes seulement, sans coûts ni marge.
        $this->actingAs($cashier['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2000)
            ->where('summary.margin', null)
            ->where('summary.stock_value', null)
            ->where('can.all', false));
        $this->actingAs($cashier['user'])->get(route('reports.index', ['format' => 'csv']))->assertForbidden();

        $csv = $this->actingAs($setup['user'])->get(route('reports.index', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString(';2;8000', $csv->streamedContent());
        $this->assertTrue(AuditEvent::query()->where('action', 'report.exported')->exists());
    }

    public function test_own_scope_shows_nothing_to_a_user_without_sales(): void
    {
        $setup = $this->createTenantWithMember();
        $purchaser = $this->member($setup, 'purchaser');

        // Responsable achats : report.view_own accordé, donc accès limité à ses propres ventes (aucune).
        $this->actingAs($purchaser['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page->where('summary.total', 0));
    }
}
