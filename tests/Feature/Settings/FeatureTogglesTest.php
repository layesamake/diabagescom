<?php

namespace Tests\Feature\Settings;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\Ledger;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\FeatureToggle;
use App\Models\Plan;
use App\Models\Storefront;
use App\Models\StorefrontOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Comptabilité et boutique en ligne sont incluses dans toutes les formules
 * mais désactivées par défaut : seul l'administrateur peut les activer, et
 * une désactivation est refusée si le module contient déjà des données.
 */
class FeatureTogglesTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_a_newly_created_company_starts_with_both_features_deactivated(): void
    {
        $setup = $this->createTenantWithMember(activateAccounting: false, activateOnlineStore: false);

        $access = app(FeatureAccess::class);
        $this->assertFalse($access->hasFeature($setup['tenant'], 'accounting'));
        $this->assertFalse($access->hasFeature($setup['tenant'], 'online_store'));
    }

    public function test_the_admin_can_activate_a_feature_included_in_the_plan(): void
    {
        $setup = $this->createTenantWithMember(activateAccounting: false);

        $this->actingAs($setup['user'])->put(route('features.update', 'accounting'), ['enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($setup['tenant']->fresh()->accounting_activated);
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.feature_activated', 'entity_id' => $setup['tenant']->id]);
    }

    public function test_a_feature_cannot_be_activated_when_the_plan_does_not_include_it(): void
    {
        $setup = $this->createTenantWithMember(activateAccounting: false);
        Plan::query()->where('code', 'business_accounting')->update(['accounting_enabled' => false]);

        $this->actingAs($setup['user'])->put(route('features.update', 'accounting'), ['enabled' => true])
            ->assertSessionHasErrors('feature');

        $this->assertFalse($setup['tenant']->fresh()->accounting_activated);
    }

    public function test_only_the_admin_role_can_toggle_a_feature(): void
    {
        $setup = $this->createTenantWithMember(activateAccounting: false);
        $management = $this->member($setup, 'management');

        $this->actingAs($management['user'])->put(route('features.update', 'accounting'), ['enabled' => true])
            ->assertForbidden();

        $this->assertFalse($setup['tenant']->fresh()->accounting_activated);
    }

    public function test_disabling_accounting_is_blocked_when_journal_entries_exist(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());
        app(Ledger::class)->post('OD', CarbonImmutable::today(), 'Apport', [
            EntryLine::debit('521', 10000, 'x'), EntryLine::credit('101', 10000, 'x'),
        ], $setup['user']);

        $this->expectException(ValidationException::class);
        app(FeatureToggle::class)->setEnabled($setup['tenant'], 'accounting', false, $setup['user']);
    }

    public function test_disabling_online_store_is_blocked_when_orders_exist(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        $storefront = Storefront::query()->forceCreate(['tenant_id' => $setup['tenant']->id, 'slug' => 'ma-boutique', 'enabled' => true]);
        StorefrontOrder::query()->forceCreate([
            'tenant_id' => $setup['tenant']->id, 'storefront_id' => $storefront->id, 'number' => 'CMD-1',
            'customer_name' => 'Client', 'customer_phone' => '770000000',
        ]);

        $this->expectException(ValidationException::class);
        app(FeatureToggle::class)->setEnabled($setup['tenant'], 'online_store', false, $setup['user']);
    }

    public function test_disabling_a_feature_without_data_succeeds_and_is_audited(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->put(route('features.update', 'online_store'), ['enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($setup['tenant']->fresh()->online_store_activated);
        $this->assertDatabaseHas('audit_events', ['action' => 'tenant.feature_deactivated', 'entity_id' => $setup['tenant']->id]);
    }
}
