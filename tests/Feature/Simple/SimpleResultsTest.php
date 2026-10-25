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
                ->where('by_day.0.day', '2026-10-24')
                ->where('by_day.0.total', 6000)
                ->where('by_day.0.sales', 1)
                ->where('can.margin', true)
                ->where('audio_code', 'PIL-01'));

        CarbonImmutable::setTestNow();
    }

    /** @return array{0: string, 1: string} Début et fin de la période de comparaison. */
    private function previousPeriod(array $setup, string $now, string $period): array
    {
        CarbonImmutable::setTestNow($now);
        $previous = [];

        $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id])
            ->get("/simple/resultats?period={$period}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$previous): void {
                $page->where('period.previous', function ($range) use (&$previous): bool {
                    $previous = [$range['from'], $range['to']];

                    return true;
                });
            });

        return $previous;
    }

    public function test_the_comparison_period_follows_the_calendar_not_a_window_of_the_same_length(): void
    {
        $setup = $this->createTenantWithMember();

        // Au 25 octobre : octobre depuis le 1er se compare à septembre du 1er au 25.
        $this->assertSame(['2026-09-01', '2026-09-25'], $this->previousPeriod($setup, '2026-10-25 10:00:00', 'month'));
        // Semaine du lundi 19 au dimanche 25 : la semaine d'avant, du 12 au 18.
        $this->assertSame(['2026-10-12', '2026-10-18'], $this->previousPeriod($setup, '2026-10-25 10:00:00', 'week'));
        $this->assertSame(['2025-01-01', '2025-10-25'], $this->previousPeriod($setup, '2026-10-25 10:00:00', 'year'));
        // Le 31 mars : février n'a pas de 31, la comparaison s'arrête au 28.
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->previousPeriod($setup, '2026-03-31 10:00:00', 'month'));
        // En janvier, le mois d'avant est décembre de l'année précédente.
        $this->assertSame(['2025-12-01', '2025-12-15'], $this->previousPeriod($setup, '2026-01-15 10:00:00', 'month'));

        CarbonImmutable::setTestNow();
    }

    public function test_the_comparison_says_how_much_more_was_sold_than_the_same_days_last_month(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup);
        $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);

        CarbonImmutable::setTestNow('2026-10-10 10:00:00');
        $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);

        CarbonImmutable::setTestNow('2026-10-25 10:00:00');
        $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id])
            ->get('/simple/resultats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.sold', 6000)
                ->where('comparison.total.value', 2000)
                ->where('comparison.total.change', 4000));

        CarbonImmutable::setTestNow();
    }
}
