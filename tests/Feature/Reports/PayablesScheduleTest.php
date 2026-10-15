<?php

namespace Tests\Feature\Reports;

use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Échéancier des dettes fournisseurs (Pilotage) : échu, 7 jours, 8 à 30 jours,
 * au-delà, et factures sans échéance (jamais rangées dans une tranche inventée).
 */
class PayablesScheduleTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function invoice(array $setup, int $total, ?string $due, ?string $warehouseId = null): SupplierInvoice
    {
        $this->enter($setup);
        $supplier = $this->makeSupplier($setup);
        $service = app(SupplierInvoiceService::class);
        $draft = $service->saveDraft(null, [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouseId ?? $setup['warehouse']->id,
            'supplier_reference' => 'REF-'.Str::random(8),
            'due_date' => $due ?? '2026-12-31',
            'lines' => [['label' => 'Riz', 'quantity' => '1', 'unit_cost' => $total, 'tax_rate_basis_points' => 0]],
        ], $setup['user']);
        $invoice = $service->validate($draft, $setup['user']);

        if ($due === null) {
            // Le service impose toujours une échéance : on simule une facture historique sans date.
            DB::statement('ALTER TABLE supplier_invoices DISABLE TRIGGER supplier_invoices_frozen');
            DB::table('supplier_invoices')->where('id', $invoice->id)->update(['due_date' => null]);
            DB::statement('ALTER TABLE supplier_invoices ENABLE TRIGGER supplier_invoices_frozen');
        }

        return $invoice;
    }

    public function test_the_schedule_splits_balances_by_due_date_and_adds_up_to_the_total(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();

        $this->invoice($setup, 1000, '2026-10-13');   // échu (hier)
        $this->invoice($setup, 2000, '2026-10-14');   // aujourd'hui → 7 jours
        $this->invoice($setup, 4000, '2026-10-21');   // J+7 inclus
        $this->invoice($setup, 8000, '2026-10-22');   // J+8
        $this->invoice($setup, 16000, '2026-11-13');  // J+30 inclus
        $this->invoice($setup, 32000, '2026-11-14');  // J+31 → plus tard
        $this->invoice($setup, 64000, null);          // sans échéance

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payablesSchedule.overdue.amount', 1000)
            ->where('payablesSchedule.overdue.count', 1)
            ->where('payablesSchedule.due_7.amount', 6000)
            ->where('payablesSchedule.due_7.count', 2)
            ->where('payablesSchedule.due_8_30.amount', 24000)
            ->where('payablesSchedule.due_8_30.count', 2)
            ->where('payablesSchedule.later.amount', 32000)
            ->where('payablesSchedule.later.count', 1)
            ->where('payablesSchedule.missing_due.amount', 64000)
            ->where('payablesSchedule.missing_due.count', 1)
            ->where('payablesSchedule.total', 127000)
            ->where('summary.payables', 127000));

        CarbonImmutable::setTestNow();
    }

    public function test_partial_payments_reduce_the_balance_and_settled_invoices_disappear(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();
        $payments = app(SupplierPaymentService::class);

        $partial = $this->invoice($setup, 10000, '2026-10-10');
        $payments->pay($partial, 'transfer', 3000, null, 'VIR-1', $setup['user'], (string) Str::uuid());
        $settled = $this->invoice($setup, 5000, '2026-10-10');
        $payments->pay($settled, 'transfer', 5000, null, 'VIR-2', $setup['user'], (string) Str::uuid());

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payablesSchedule.overdue.amount', 7000)
            ->where('payablesSchedule.overdue.count', 1)
            ->where('payablesSchedule.total', 7000)
            ->where('summary.payables', 7000));

        CarbonImmutable::setTestNow();
    }

    public function test_other_tenants_and_unauthorized_warehouses_are_never_counted(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();
        $foreign = $this->createTenantWithMember();
        $secondWarehouse = $this->makeWarehouse($setup, 'THS');

        $this->invoice($setup, 1000, '2026-10-10');
        $this->invoice($foreign, 99000, '2026-10-10');
        $this->invoice($setup, 77000, '2026-10-10', $secondWarehouse->id);

        // Direction affectée au seul dépôt principal : ni l'autre dépôt ni l'autre entreprise.
        $management = $this->member($setup, 'management');

        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payablesSchedule.total', 1000)
            ->where('payablesSchedule.overdue.amount', 1000));

        CarbonImmutable::setTestNow();
    }

    public function test_the_schedule_needs_purchase_view_and_global_reports(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();
        $this->invoice($setup, 1000, '2026-10-10');

        // report.view_own seulement : pas de vue d'ensemble des dettes.
        $manager = $this->member($setup, 'warehouse_manager');
        $this->actingAs($manager['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payablesSchedule', null));

        // report.view_all mais sans purchase.view.
        $management = $this->member($setup, 'management');
        DB::table('role_permissions')->where('role_id', $management['membership']->role_id)->where('permission_code', 'purchase.view')->delete();
        $this->actingAs($management['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payablesSchedule', null));

        CarbonImmutable::setTestNow();
    }

    public function test_overdue_supplier_invoices_raise_a_priority_with_an_action(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();
        $this->invoice($setup, 1000, '2026-10-10');
        $this->invoice($setup, 2500, '2026-10-01');
        $this->invoice($setup, 9000, '2026-12-01');

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->contains(
                fn ($p) => $p['type'] === 'payables_overdue' && $p['amount'] === 3500 && $p['count'] === 2,
            )));

        CarbonImmutable::setTestNow();
    }

    public function test_no_priority_when_nothing_is_overdue(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        $setup = $this->createTenantWithMember();
        $this->invoice($setup, 9000, '2026-12-01');

        $this->actingAs($setup['user'])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('priorities', fn ($priorities) => collect($priorities)->doesntContain(fn ($p) => $p['type'] === 'payables_overdue')));

        CarbonImmutable::setTestNow();
    }
}
