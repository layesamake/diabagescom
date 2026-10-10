<?php

namespace Tests\Feature\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartiesAndReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private function in(array $setup, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($setup['tenant'], $callback);
    }

    public function test_customers_get_sequential_codes_and_integer_credit_limits(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('customers.store'), ['name' => 'Moussa Sow', 'credit_limit' => '150 000', 'phone' => '+221 77 123 45 67'])->assertSessionHasNoErrors();
        $this->actingAs($setup['user'])->post(route('customers.store'), ['name' => 'Fatou Ba'])->assertSessionHasNoErrors();

        $customers = $this->in($setup, fn () => Customer::query()->orderBy('code')->get());
        $this->assertSame(['C-00001', 'C-00002'], $customers->pluck('code')->all());
        $this->assertSame(150000, $customers[0]->credit_limit);
        $this->assertNull($customers[1]->credit_limit);
    }

    public function test_supplier_codes_have_their_own_sequence(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('customers.store'), ['name' => 'Client']);
        $this->actingAs($setup['user'])->post(route('suppliers.store'), ['name' => 'Grossiste Dakar'])->assertSessionHasNoErrors();

        $this->assertSame('F-00001', $this->in($setup, fn () => Supplier::query()->value('code')));
    }

    public function test_credit_limit_changes_are_audited(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('customers.store'), ['name' => 'Moussa Sow', 'credit_limit' => 100000]);
        $customer = $this->in($setup, fn () => Customer::query()->sole());

        $this->actingAs($setup['user'])->put(route('customers.update', $customer->id), ['name' => 'Moussa Sow', 'credit_limit' => 250000])->assertSessionHasNoErrors();

        $event = AuditEvent::query()->where('action', 'customer.updated')->sole();
        $this->assertSame(['credit_limit' => 100000], $event->before);
        $this->assertSame(['credit_limit' => 250000], $event->after);
    }

    public function test_cashiers_can_manage_customers_but_not_suppliers(): void
    {
        $setup = $this->createTenantWithMember('cashier');

        $this->actingAs($setup['user'])->post(route('customers.store'), ['name' => 'Client comptoir'])->assertSessionHasNoErrors();
        $this->actingAs($setup['user'])->get(route('suppliers.index'))->assertForbidden();
    }

    public function test_category_cycles_are_refused(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('catalog.categories.save'), ['name' => 'Alimentation']);
        $parent = $this->in($setup, fn () => ProductCategory::query()->sole());
        $this->actingAs($setup['user'])->post(route('catalog.categories.save'), ['name' => 'Riz', 'parent_id' => $parent->id]);
        $child = $this->in($setup, fn () => ProductCategory::query()->where('name', 'Riz')->sole());

        $this->actingAs($setup['user'])
            ->post(route('catalog.categories.save', ['category' => $parent->id]), ['name' => 'Alimentation', 'parent_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_tax_rates_are_stored_in_basis_points_with_a_single_default(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('catalog.tax_rates.save'), [
            'name' => 'TVA réduite', 'rate_percent' => '2,5', 'is_default' => 1, 'active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $taxes = $this->in($setup, fn () => TaxRate::query()->get()->keyBy('name'));
        $this->assertSame(250, $taxes['TVA réduite']->rate_basis_points);
        $this->assertTrue($taxes['TVA réduite']->is_default);
        $this->assertFalse($taxes['TVA 18 %']->is_default);
    }

    public function test_only_administrators_change_tax_rates(): void
    {
        $setup = $this->createTenantWithMember('purchaser');

        $this->actingAs($setup['user'])->post(route('catalog.tax_rates.save'), [
            'name' => 'Pirate', 'rate_percent' => '1', 'is_default' => 0, 'active' => 1,
        ])->assertForbidden();
    }

    public function test_new_companies_start_with_vat_18_and_common_units(): void
    {
        $setup = $this->createTenantWithMember();

        $this->assertSame(1800, $this->in($setup, fn () => TaxRate::query()->where('is_default', true)->value('rate_basis_points')));
        $this->assertSame(7, $this->in($setup, fn () => Unit::query()->count()));
    }
}
