<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restructuration commerciale validée le 17/10/2026 : 3 formules, comptabilité
 * et boutique en ligne incluses partout (activables par l'administrateur).
 */
class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_three_plans_are_sellable(): void
    {
        $codes = Plan::query()->where('active', true)->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(['essential', 'business', 'business_accounting'], $codes);
    }

    public function test_every_sellable_plan_includes_accounting_and_online_store(): void
    {
        $plans = Plan::query()->where('active', true)->get();

        foreach ($plans as $plan) {
            $this->assertTrue($plan->accounting_enabled, "{$plan->code} devrait inclure la comptabilité.");
            $this->assertTrue($plan->online_store_enabled, "{$plan->code} devrait inclure la boutique en ligne.");
        }
    }

    public function test_prices_match_the_validated_pricing_grid(): void
    {
        $this->assertPrices('essential', 5000, 51000);
        $this->assertPrices('business', 12500, 120000);
        $this->assertPrices('business_accounting', 15000, 135000);
    }

    public function test_business_accounting_is_now_named_premium(): void
    {
        $this->assertSame('Premium', Plan::query()->where('code', 'business_accounting')->value('name'));
    }

    private function assertPrices(string $code, int $monthly, int $yearly): void
    {
        $plan = Plan::query()->where('code', $code)->firstOrFail();
        $this->assertSame($monthly, $plan->price_monthly);
        $this->assertSame($yearly, $plan->price_yearly);
    }
}
