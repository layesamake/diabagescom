<?php

namespace Tests;

use App\Domain\Catalog\SeedTenantCatalogDefaults;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Warehouse;
use Database\Factories\TenantFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** Permissions et rôles intégrés sont présents dans chaque test (RefreshDatabase). */
    protected bool $seed = true;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Crée une entreprise avec un dépôt et un membre (MFA configurée par défaut,
     * puisque les administrateurs y sont contraints).
     *
     * @return array{tenant: Tenant, user: User, membership: TenantMembership, warehouse: Warehouse}
     */
    protected function createTenantWithMember(string $role = 'admin', ?User $user = null, ?string $plan = 'business_accounting'): array
    {
        $user ??= User::factory()->withTwoFactor()->create();
        $tenant = Tenant::factory()->create();
        $membership = TenantFactory::addMember($tenant, $user, $role);
        $warehouse = app(TenantContext::class)->runAs($tenant, function () {
            app(SeedTenantCatalogDefaults::class)();

            return Warehouse::factory()->create();
        });

        if ($plan !== null) {
            app(SubscriptionLifecycle::class)->startTrial($tenant, Plan::query()->where('code', $plan)->firstOrFail());
        }

        return compact('tenant', 'user', 'membership', 'warehouse');
    }
}
