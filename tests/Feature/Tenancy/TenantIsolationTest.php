<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantAccess;
use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Non-divulgation entre deux entreprises (critère de fin d'EPIC-01).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owned_queries_fail_closed_without_context(): void
    {
        $this->createTenantWithMember();

        $this->expectException(MissingTenantContext::class);

        Warehouse::query()->count();
    }

    public function test_queries_only_return_rows_of_the_current_tenant(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();
        $context = app(TenantContext::class);

        $ids = $context->runAs($a['tenant'], fn () => Warehouse::query()->pluck('id')->all());

        $this->assertSame([$a['warehouse']->id], $ids);
        $this->assertNull($context->runAs($a['tenant'], fn () => Warehouse::query()->find($b['warehouse']->id)));
    }

    public function test_a_record_cannot_be_created_for_another_tenant(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->expectException(CrossTenantAccess::class);

        app(TenantContext::class)->runAs($a['tenant'], function () use ($b) {
            $warehouse = new Warehouse(['code' => 'X1', 'name' => 'Intrus']);
            $warehouse->tenant_id = $b['tenant']->id;
            $warehouse->save();
        });
    }

    public function test_tenant_id_is_immutable(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->expectException(CrossTenantAccess::class);

        app(TenantContext::class)->runAs($a['tenant'], function () use ($a, $b) {
            $a['warehouse']->tenant_id = $b['tenant']->id;
            $a['warehouse']->save();
        });
    }

    public function test_database_rejects_linking_a_member_to_another_tenants_warehouse(): void
    {
        $a = $this->createTenantWithMember('cashier');
        $b = $this->createTenantWithMember();

        $this->expectException(QueryException::class);

        // Même en contournant l'application, la clé étrangère composite refuse le mélange.
        DB::table('warehouse_memberships')->insert([
            'tenant_id' => $a['tenant']->id,
            'warehouse_id' => $b['warehouse']->id,
            'membership_id' => $a['membership']->id,
        ]);
    }

    public function test_a_user_cannot_switch_to_a_company_they_do_not_belong_to(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->actingAs($a['user'])
            ->post(route('context.tenant'), ['tenant_id' => $b['tenant']->id])
            ->assertNotFound();
    }

    public function test_a_forged_session_tenant_is_ignored(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->actingAs($a['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $b['tenant']->id])
            ->get(route('simple.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('context.tenant.id', $a['tenant']->id));
    }

    public function test_a_user_cannot_select_a_warehouse_of_another_company(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->actingAs($a['user'])
            ->post(route('context.warehouse'), ['warehouse_id' => $b['warehouse']->id])
            ->assertNotFound();
    }

    public function test_shared_context_never_lists_other_companies(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        $this->actingAs($a['user'])
            ->get(route('simple.home'))
            ->assertInertia(fn ($page) => $page
                ->where('context.tenants', [['id' => $a['tenant']->id, 'name' => $a['tenant']->display_name]])
                ->where('context.warehouses.0.id', $a['warehouse']->id)
                ->count('context.warehouses', 1));
    }

    public function test_audit_log_only_shows_events_of_the_current_company(): void
    {
        $a = $this->createTenantWithMember();
        $b = $this->createTenantWithMember();

        AuditEvent::query()->create(['tenant_id' => $a['tenant']->id, 'action' => 'test.visible']);
        AuditEvent::query()->create(['tenant_id' => $b['tenant']->id, 'action' => 'test.hidden']);

        $this->actingAs($a['user'])
            ->get(route('audit.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('events.data', fn ($events) => collect($events)->pluck('action')->contains('test.visible')
                    && ! collect($events)->pluck('action')->contains('test.hidden')));
    }

    public function test_revoked_membership_loses_access_immediately(): void
    {
        $a = $this->createTenantWithMember();
        $this->actingAs($a['user'])->get(route('simple.home'))->assertOk();

        $a['membership']->forceFill(['status' => 'revoked'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('onboarding.create'));
    }
}
