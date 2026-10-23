<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 6 : le parcours tactile réutilise le guide métier
 * existant, au lieu de mémoriser une seconde progression propre à son écran.
 */
class SimpleGuideTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $admin = $this->createTenantWithMember();

        $this->act($admin)->get('/simple/guide')->assertNotFound();
    }

    public function test_the_page_reuses_the_shared_start_guide_for_its_steps_and_next_action(): void
    {
        $admin = $this->createTenantWithMember();

        $this->act($admin)
            ->get('/simple/guide')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/PremiersPas')
                ->where('guide.steps.0.key', 'company')
                ->where('guide.next', 'company'));
    }

    public function test_the_steps_follow_the_current_users_role(): void
    {
        $admin = $this->createTenantWithMember();
        $cashier = $this->member($admin, 'cashier');

        $this->act($cashier)
            ->get('/simple/guide')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('guide.track', 'cashier')
                ->where('guide.steps', fn ($steps) => collect($steps)->pluck('key')->doesntContain('voice')));
    }
}
