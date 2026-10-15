<?php

namespace Tests\Feature\Guide;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class StartGuideTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * @param  list<string>  $keys
     */
    private function stepsAre(array $keys): Closure
    {
        return fn ($steps) => collect($steps)->pluck('key')->all() === $keys;
    }

    public function test_the_admin_guide_ticks_itself_when_the_data_exists(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.track', 'admin')
            ->where('guide.next', 'company')
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'products')['status'] === 'todo'));

        $this->makeProduct($setup);
        DB::table('tenant_profiles')->updateOrInsert(
            ['tenant_id' => $setup['tenant']->id],
            ['vat_regime' => 'real', 'phone' => '771234567', 'created_at' => now(), 'updated_at' => now()],
        );

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.next', 'stock')
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'products')['status'] === 'done'
                && collect($steps)->firstWhere('key', 'products')['auto'] === true
                && collect($steps)->firstWhere('key', 'company')['status'] === 'done'));
    }

    public function test_the_admin_guide_stays_short_despite_having_every_permission(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', $this->stepsAre(['company', 'products', 'stock', 'cash', 'sale', 'customer', 'team', 'backup', 'supplier', 'accounting'])));
    }

    public function test_each_role_only_gets_the_steps_its_permissions_allow(): void
    {
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');
        $warehouse = $this->member($setup, 'warehouse_manager');

        $this->actingAs($cashier['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.track', 'cashier')
            ->where('guide.steps', $this->stepsAre(['cash', 'sale', 'customer'])));

        $this->actingAs($warehouse['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.track', 'warehouse')
            ->where('guide.steps', $this->stepsAre(['stock', 'receipt', 'count', 'reports'])));
    }

    public function test_a_non_admin_only_gets_credit_for_what_they_did_themselves(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup, 0);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'sale')['status'] === 'done'
                && collect($steps)->firstWhere('key', 'cash')['status'] === 'done'));

        $this->actingAs($cashier['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'sale')['status'] === 'todo'
                && collect($steps)->firstWhere('key', 'cash')['status'] === 'todo'));
    }

    public function test_a_step_can_be_ticked_skipped_and_reopened_by_the_user_only(): void
    {
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($setup['user'])->post(route('guide.steps.update', 'backup'), ['status' => 'done'])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'backup')['status'] === 'done'
                && collect($steps)->firstWhere('key', 'backup')['auto'] === false));

        $this->post(route('guide.steps.update', 'backup'), ['status' => 'skipped'])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'backup')['status'] === 'skipped'));

        $this->post(route('guide.steps.update', 'backup'), ['status' => 'todo'])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->firstWhere('key', 'backup')['status'] === 'todo'));
        $this->assertDatabaseCount('guide_steps', 0);

        // Une étape que son rôle ne propose pas, ou inconnue : introuvable.
        $this->actingAs($cashier['user'])->post(route('guide.steps.update', 'backup'), ['status' => 'done'])->assertNotFound();
        $this->actingAs($setup['user'])->post(route('guide.steps.update', 'nope'), ['status' => 'done'])->assertNotFound();
        $this->post(route('guide.steps.update', 'backup'), ['status' => 'maybe'])->assertSessionHasErrors('status');
    }

    public function test_hiding_the_card_and_the_tour_are_remembered_per_user(): void
    {
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.dismissed', false)->where('guide.tour_seen', false));

        $this->put(route('guide.update'), ['dismissed' => true, 'tour_seen' => true])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.dismissed', true)->where('guide.tour_seen', true));

        $this->put(route('guide.update'), ['dismissed' => false])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.dismissed', false)->where('guide.tour_seen', true));

        $this->actingAs($cashier['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.dismissed', false)->where('guide.tour_seen', false));
    }

    public function test_accounting_steps_need_the_accounting_plan(): void
    {
        $with = $this->createTenantWithMember();
        $without = $this->createTenantWithMember(plan: 'business');

        $this->actingAs($with['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => collect($steps)->pluck('key')->contains('accounting')));

        $this->actingAs($without['user'])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('guide.steps', fn ($steps) => ! collect($steps)->pluck('key')->contains('accounting')
                && ! collect($steps)->pluck('key')->contains('entry')));
    }

    public function test_the_guide_page_renders_and_requires_a_login(): void
    {
        $this->get(route('guide.index'))->assertRedirect(route('login'));

        $this->actingAs($this->createTenantWithMember()['user'])->get(route('guide.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('guide/Index')->has('guide.steps'));
    }
}
