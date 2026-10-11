<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\Ledger;
use App\Models\AuditEvent;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class AccountingHttpTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function initialized(): array
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());

        return $setup;
    }

    public function test_setup_through_the_interface_and_pages_render(): void
    {
        $setup = $this->createTenantWithMember();
        $user = $setup['user'];

        $this->actingAs($user)->get(route('accounting.index'))->assertInertia(fn (Assert $p) => $p->component('accounting/Overview')->where('settings', null));
        $this->actingAs($user)->post(route('accounting.setup'), [
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
            'posting_starts_on' => now()->toDateString(),
        ])->assertRedirect();

        $this->actingAs($user)->get(route('accounting.index'))->assertInertia(fn (Assert $p) => $p->has('years', 1)->has('years.0.periods', 12));
        foreach (['accounting.entries.index' => 'accounting/Entries', 'accounting.entries.create' => 'accounting/EntryForm',
            'accounting.accounts.index' => 'accounting/Accounts', 'accounting.reports' => 'accounting/Reports',
            'accounting.reconciliations.index' => 'accounting/Reconciliation'] as $route => $component) {
            $this->actingAs($user)->get(route($route))->assertInertia(fn (Assert $p) => $p->component($component));
        }
    }

    public function test_manual_entry_over_http_and_csv_export_is_audited(): void
    {
        $setup = $this->initialized();

        $this->actingAs($setup['user'])->post(route('accounting.entries.store'), [
            'journal' => 'OD', 'entry_date' => now()->toDateString(), 'description' => 'Apport en capital',
            'lines' => [['account' => '521', 'debit' => 250000], ['account' => '101', 'credit' => 250000]],
        ])->assertRedirect();
        $entry = JournalEntry::query()->withoutGlobalScopes()->firstOrFail();
        $this->actingAs($setup['user'])->post(route('accounting.entries.post', $entry->id))->assertRedirect();
        $this->assertSame(JournalEntry::POSTED, $entry->refresh()->status);

        $csv = $this->actingAs($setup['user'])->get(route('accounting.reports', ['report' => 'balance', 'format' => 'csv']));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('521;Banques;0;250000;0;250000', $content);
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'accounting.exported')->exists());
    }

    public function test_accounting_is_reserved_to_compta_plans_and_authorized_roles(): void
    {
        $noCompta = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($noCompta['user'])->get(route('accounting.index'))->assertRedirect();

        $setup = $this->initialized();
        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('accounting.index'))->assertForbidden();
        $this->actingAs($cashier['user'])->post(route('accounting.post_pending'))->assertForbidden();

        // La direction consulte mais ne saisit pas.
        $management = $this->member($setup, 'management');
        $this->actingAs($management['user'])->get(route('accounting.reports'))->assertOk();
        $this->actingAs($management['user'])->post(route('accounting.entries.store'), [])->assertForbidden();
    }

    public function test_another_company_cannot_see_entries(): void
    {
        $owner = $this->initialized();
        $this->enter($owner);
        $entry = app(Ledger::class)->post('OD', CarbonImmutable::today(), 'X', [
            EntryLine::debit('521', 10, 'x'), EntryLine::credit('101', 10, 'x'),
        ], $owner['user']);
        $intruder = $this->initialized();

        $this->actingAs($intruder['user'])->get(route('accounting.entries.show', $entry->id))->assertNotFound();
        $this->actingAs($intruder['user'])->post(route('accounting.entries.reverse', $entry->id), ['date' => now()->toDateString(), 'reason' => 'Test'])->assertNotFound();
    }
}
