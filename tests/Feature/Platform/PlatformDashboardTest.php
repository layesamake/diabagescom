<?php

namespace Tests\Feature\Platform;

use App\Domain\Billing\SandboxProvider;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantScope;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.providers.sandbox.enabled' => true, 'billing.providers.sandbox.secret' => 'test-secret-'.Str::random(16)]);
        Plan::query()->where('code', 'business')->update(['price_monthly' => 25000, 'price_yearly' => 240000]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    private function subscriptionOf(array $setup): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole();
    }

    private function invoiceFor(array $setup): SaasInvoice
    {
        $this->actingAs($setup['user'])->post(route('billing.invoices.store'), ['plan' => 'business', 'cycle' => 'monthly'])->assertRedirect();

        return SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->latest()->firstOrFail();
    }

    private function payBySandbox(array $setup, SaasInvoice $invoice): void
    {
        $this->actingAs($setup['user'])->post(route('billing.invoices.pay', $invoice->id), [
            'provider' => 'sandbox', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $payment = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->latest()->firstOrFail();

        $body = (string) json_encode(['id' => 'evt_'.Str::random(8), 'type' => 'payment.succeeded', 'data' => ['reference' => $payment->external_reference, 'amount' => $invoice->total]]);
        $this->call('POST', route('webhooks.billing', 'sandbox'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DIABA_SIGNATURE' => app(SandboxProvider::class)->sign($body),
        ], $body)->assertOk();
    }

    public function test_dashboard_shows_recurring_revenue_trials_conversions_and_unpaid_invoices(): void
    {
        // Client payant : 25 000 F par mois.
        $paying = $this->createTenantWithMember(plan: 'business');
        $this->payBySandbox($paying, $this->invoiceFor($paying));

        // Essai qui se termine dans deux jours.
        $ending = $this->createTenantWithMember(plan: 'business');
        $this->subscriptionOf($ending)->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

        // Essai normal avec une facture en retard.
        $late = $this->createTenantWithMember(plan: 'business');
        $this->invoiceFor($late)->forceFill(['due_on' => now()->subDays(5)->toDateString()])->save();

        $this->actingAs($this->superAdmin())
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('platform/Dashboard')
                ->where('metrics.mrr', 25000)
                ->where('metrics.arr', 300000)
                ->where('metrics.statuses.active', 1)
                ->where('metrics.statuses.trialing', 2)
                ->where('metrics.tenants_total', 3)
                ->where('metrics.new_tenants.this_month', 3)
                ->where('metrics.conversions.this_month', 1)
                ->where('metrics.conversions.rate', 100)
                ->where('metrics.collected.this_month', 25000)
                ->where('metrics.unpaid.count', 1)
                ->where('metrics.unpaid.overdue_total', 25000)
                ->where('metrics.overdue_invoices.0.tenant', $late['tenant']->display_name)
                ->has('metrics.trials_ending', 1)
                ->where('metrics.trials_ending.0.tenant', $ending['tenant']->display_name)
                ->has('metrics.history', 12)
                ->where('metrics.history.11.collected', 25000)
                ->where('metrics.history.11.new_tenants', 3));
    }

    public function test_yearly_subscriptions_count_for_a_twelfth_and_suspensions_count_as_churn(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $admin = $this->superAdmin();
        $lifecycle = app(SubscriptionLifecycle::class);
        $lifecycle->activate($this->subscriptionOf($setup), 'yearly', 'Virement annuel reçu', $admin);

        $this->actingAs($admin)->get(route('platform.dashboard'))
            ->assertInertia(fn ($page) => $page->where('metrics.mrr', 20000)->where('metrics.churn_this_month', 0));

        $lifecycle->suspend($this->subscriptionOf($setup), 'Résiliation demandée', $admin);

        $this->actingAs($admin)->get(route('platform.dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('metrics.mrr', 0)
                ->where('metrics.statuses.suspended', 1)
                ->where('metrics.churn_this_month', 1));
    }

    public function test_only_the_platform_owner_sees_the_dashboard_and_lands_on_it(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('platform.dashboard'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertRedirect(route('platform.dashboard'));
    }
}
