<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class SimpleResultsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    public function test_simple_results_adapts_the_current_month_pilotage_to_the_simple_screen(): void
    {
        CarbonImmutable::setTestNow('2026-10-24 10:00:00');
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);

        $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id])
            ->get('/simple/resultats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('simple/Resultats')
                ->where('period.key', 'month')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-24')
                ->where('summary.sold', 6000)
                ->where('summary.spent', 3000)
                ->where('summary.remaining', 2085)
                ->where('top_products.0.label', 'Article RIZ')
                ->where('can.margin', true)
                ->where('audio_code', 'PIL-01'));

        CarbonImmutable::setTestNow();
    }
}
