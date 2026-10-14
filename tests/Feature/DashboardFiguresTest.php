<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Accueil : les chiffres du jour suivent les règles du rapport des ventes.
 */
class DashboardFiguresTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_today_figures_sum_validated_sales_and_receivables(): void
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup, 'RIZ', 'U', 1000);
        $customer = $this->makeCustomer($setup, creditLimit: 100000);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '20', 1000]]);
        $this->enter($setup);
        $session = $this->openSession($setup, 0);
        $this->sell($setup, $session, [[$rice->id, '3']], [['cash', 6000]]);
        $this->sell($setup, $session, [[$rice->id, '2']], [['cash', 500]], customerId: $customer->id);

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('figures.today.total', 10000)
            ->where('figures.today.sales', 2)
            ->where('figures.month', 10000)
            ->where('figures.receivables', 3500)
            ->has('figures.week', 7));
    }
}
