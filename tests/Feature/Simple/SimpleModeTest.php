<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\TenantMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Mode simple — lot 1 : bascule par appartenance, drapeau d'environnement et
 * page de comparaison des composants.
 */
class SimpleModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    public function test_the_simple_mode_does_not_exist_until_it_is_activated_on_the_environment(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        ['user' => $user] = $this->createTenantWithMember();

        $this->actingAs($user)->get('/simple')->assertNotFound();
        $this->actingAs($user)->post('/mode', ['mode' => 'simple'])->assertNotFound();
        $this->actingAs($user)->get('/simple/composants')->assertNotFound();
    }

    public function test_a_field_role_arrives_in_simple_mode_and_a_manager_in_expert_mode(): void
    {
        ['user' => $vendeur] = $this->createTenantWithMember('cashier');
        ['user' => $admin] = $this->createTenantWithMember('admin');

        $this->assertTrue($vendeur->memberships()->firstOrFail()->usesSimpleMode());
        $this->assertFalse($admin->memberships()->firstOrFail()->usesSimpleMode());
    }

    public function test_switching_changes_the_mode_of_the_membership_only(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->createTenantWithMember('admin');
        // Le même compte est comptable dans une seconde entreprise : son mode
        // y reste expert, car le choix porte sur l'appartenance.
        ['tenant' => $autre] = $this->createTenantWithMember('accountant', $user);

        $this->actingAs($user)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->post('/mode', ['mode' => 'simple'])
            ->assertRedirect('/simple');

        $this->assertSame(
            TenantMembership::MODE_SIMPLE,
            TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail()->ui_mode,
        );
        $this->assertSame(
            TenantMembership::MODE_EXPERT,
            TenantMembership::query()->where('tenant_id', $autre->id)->where('user_id', $user->id)->firstOrFail()->ui_mode,
        );
    }

    public function test_switching_back_to_expert_returns_to_the_dashboard(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->createTenantWithMember('cashier');

        $this->actingAs($user)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->post('/mode', ['mode' => 'expert'])
            ->assertRedirect('/dashboard');

        $this->assertSame(
            TenantMembership::MODE_EXPERT,
            TenantMembership::query()->where('tenant_id', $tenant->id)->firstOrFail()->ui_mode,
        );
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->createTenantWithMember('admin');

        $this->actingAs($user)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->post('/mode', ['mode' => 'colore'])
            ->assertSessionHasErrors('mode');
    }

    public function test_the_shared_context_exposes_the_mode_of_the_current_membership(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->createTenantWithMember('cashier');

        $this->actingAs($user)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->component('simple/Accueil')
                ->where('context.ui_mode', TenantMembership::MODE_SIMPLE)
                ->where('context.simple_mode_available', true));
    }

    public function test_the_component_page_is_reserved_to_the_administrator(): void
    {
        ['tenant' => $tenant, 'user' => $vendeur] = $this->createTenantWithMember('cashier');

        $this->actingAs($vendeur)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->get('/simple/composants')
            ->assertForbidden();

        ['tenant' => $autreTenant, 'user' => $admin] = $this->createTenantWithMember('admin');

        $this->actingAs($admin)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $autreTenant->id])
            ->get('/simple/composants')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('simple/Demo'));
    }

    public function test_the_dashboard_redirects_a_simple_membership_to_the_simple_home(): void
    {
        ['tenant' => $tenant, 'user' => $vendeur] = $this->createTenantWithMember('cashier');

        $this->actingAs($vendeur)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->get('/dashboard')
            ->assertRedirect('/simple');
    }

    public function test_switching_company_uses_the_mode_of_the_target_membership(): void
    {
        ['tenant' => $expert, 'user' => $user] = $this->createTenantWithMember('admin');
        ['tenant' => $simple] = $this->createTenantWithMember('cashier', $user);

        $this->actingAs($user)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $expert->id])
            ->post('/context/tenant', ['tenant_id' => $simple->id])
            ->assertRedirect('/simple');
    }

    public function test_the_mockups_are_private_and_reserved_to_the_administrator(): void
    {
        $this->assertFileDoesNotExist(public_path('maquettes/simple/01-accueil.png'));

        ['tenant' => $tenant, 'user' => $vendeur] = $this->createTenantWithMember('cashier');
        $this->actingAs($vendeur)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
            ->get('/simple/maquettes/01-accueil.png')
            ->assertForbidden();

        ['tenant' => $autreTenant, 'user' => $admin] = $this->createTenantWithMember('admin');
        $this->actingAs($admin)
            ->withSession([ResolveTenantContext::SESSION_TENANT => $autreTenant->id])
            ->get('/simple/maquettes/01-accueil.png')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_the_mode_change_is_rolled_back_if_the_audit_cannot_be_written(): void
    {
        ['tenant' => $tenant, 'user' => $admin, 'membership' => $membership] = $this->createTenantWithMember('admin');
        Schema::drop('audit_events');

        try {
            $this->withoutExceptionHandling()
                ->actingAs($admin)
                ->withSession([ResolveTenantContext::SESSION_TENANT => $tenant->id])
                ->post('/mode', ['mode' => 'simple']);
            $this->fail('La requête devait échouer lorsque le journal d’audit est indisponible.');
        } catch (QueryException) {
            // Le journal indisponible reproduit une panne entre la mutation et
            // son audit. La transaction doit annuler la mutation.
        }

        $this->assertSame(TenantMembership::MODE_EXPERT, $membership->fresh()->ui_mode);
    }
}
