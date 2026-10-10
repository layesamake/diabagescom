<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'legal_name' => 'Boutique Diallo SARL',
            'display_name' => 'Boutique Diallo',
            'country_code' => 'SN',
            'timezone' => 'Africa/Dakar',
            'warehouse_name' => 'Dakar Centre',
            'warehouse_code' => 'dkr-1',
            ...$overrides,
        ];
    }

    public function test_a_user_without_company_is_sent_to_onboarding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.create'));
    }

    public function test_onboarding_creates_company_admin_and_first_warehouse(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->payload())
            ->assertRedirect(route('dashboard'));

        $tenant = Tenant::query()->sole();
        $this->assertSame('Boutique Diallo', $tenant->display_name);
        $this->assertSame('XOF', $tenant->currency_code);

        $membership = TenantMembership::query()->with('role')->sole();
        $this->assertTrue($membership->isAdmin());
        $this->assertSame($user->id, $membership->user_id);

        $this->assertDatabaseHas('warehouses', ['tenant_id' => $tenant->id, 'code' => 'DKR-1', 'name' => 'Dakar Centre']);
        $this->assertEqualsCanonicalizing(
            ['tenant.created', 'membership.created', 'warehouse.created'],
            AuditEvent::query()->where('tenant_id', $tenant->id)->pluck('action')->all(),
        );
    }

    public function test_new_admin_must_set_up_mfa_before_using_the_company_space(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('onboarding.store'), $this->payload());

        $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_admin_with_mfa_sees_the_company_context(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user)->post(route('onboarding.store'), $this->payload());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('context.tenant.name', 'Boutique Diallo')
                ->where('context.warehouse.code', 'DKR-1')
                ->where('context.role.code', 'admin'));
    }

    public function test_invalid_data_is_rejected_and_nothing_is_created(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('onboarding.store'), $this->payload(['legal_name' => '', 'warehouse_code' => 'bad code!', 'country_code' => 'FR']))
            ->assertSessionHasErrors(['legal_name', 'warehouse_code', 'country_code']);

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_creation_is_atomic(): void
    {
        $user = User::factory()->create();

        // Un échec en cours de route (ici : trigger refusant l'insertion du dépôt) annule tout.
        DB::unprepared("CREATE FUNCTION test_fail() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'boom'; END; \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER test_fail BEFORE INSERT ON warehouses FOR EACH ROW EXECUTE FUNCTION test_fail();");

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post(route('onboarding.store'), $this->payload());
            $this->fail('An exception was expected.');
        } catch (\Throwable) {
            // attendu
        }

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_memberships', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unverified_users_cannot_create_a_company(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->post(route('onboarding.store'), $this->payload())
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('tenants', 0);
    }
}
