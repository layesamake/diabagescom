<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ProviderRegistry;
use App\Domain\Billing\SaasBilling;
use App\Domain\Tenancy\TenantScope;
use App\Models\PaymentProviderEvent;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeBillingProvider;
use Tests\TestCase;

/**
 * Cadre commun des prestataires réels, éprouvé avec un prestataire factice :
 * signature, rejeu, montant, rapprochement d'un webhook perdu, expiration et
 * garde-fou de production. Un vrai adaptateur (Wave, Orange Money, carte)
 * devra passer les mêmes scénarios.
 */
class LiveProviderFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeBillingProvider::reset();
        config(['billing.adapters' => [FakeBillingProvider::class]]);
        $this->app->forgetInstance(ProviderRegistry::class);
        Plan::query()->where('code', 'business')->update(['price_monthly' => 25000, 'price_yearly' => 250000]);
    }

    private function pendingPayment(): array
    {
        $setup = $this->createTenantWithMember();
        $billing = app(SaasBilling::class);
        $invoice = $billing->createInvoice($setup['tenant'], Plan::query()->where('code', 'business')->firstOrFail(), 'monthly', [], $setup['user']);
        $payment = $billing->startPayment($invoice, 'fake', $setup['user'], (string) Str::uuid());

        return [...$setup, 'invoice' => $invoice, 'payment' => $payment];
    }

    private function webhook(SaasPayment $payment, string $state = 'succeeded', ?int $amount = null, ?string $id = null, ?string $signature = null)
    {
        $body = json_encode(['id' => $id ?? 'evt_'.Str::random(8), 'reference' => $payment->external_reference, 'amount' => $amount ?? $payment->amount, 'state' => $state]);

        return $this->call('POST', route('webhooks.billing', 'fake'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $signature ?? FakeBillingProvider::sign($body)], $body);
    }

    private function periodEnd(array $setup): string
    {
        return (string) Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole()->current_period_end;
    }

    public function test_checkout_creates_a_pending_payment_and_nothing_is_activated(): void
    {
        $s = $this->pendingPayment();

        $this->assertSame(SaasPayment::PENDING, $s['payment']->status);
        $this->assertStringStartsWith('https://pay.example.test/', (string) $s['payment']->checkout_url);
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);
    }

    public function test_a_forged_webhook_is_refused_and_a_valid_one_settles_once(): void
    {
        $s = $this->pendingPayment();

        $this->webhook($s['payment'], signature: 'forged')->assertUnauthorized();
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);

        $this->webhook($s['payment'], id: 'evt_1')->assertOk();
        $this->assertSame(SaasInvoice::PAID, $s['invoice']->refresh()->status);
        $end = $this->periodEnd($s);

        $this->webhook($s['payment'], id: 'evt_1')->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertSame($end, $this->periodEnd($s));
    }

    public function test_a_wrong_amount_goes_to_review_without_activating(): void
    {
        $s = $this->pendingPayment();

        $this->webhook($s['payment'], amount: 1)->assertOk();

        $this->assertSame(SaasPayment::REQUIRES_REVIEW, $s['payment']->refresh()->status);
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);
    }

    public function test_a_lost_webhook_is_recovered_by_reconciliation_exactly_once(): void
    {
        $s = $this->pendingPayment();
        FakeBillingProvider::$remote[$s['payment']->external_reference]['state'] = 'succeeded';

        // Trop récent : on laisse d'abord sa chance au webhook.
        $this->artisan('diaba:billing:reconcile')->assertSuccessful();
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);

        $this->travel(5)->minutes();
        $this->artisan('diaba:billing:reconcile')->assertSuccessful();
        $this->assertSame(SaasInvoice::PAID, $s['invoice']->refresh()->status);
        $end = $this->periodEnd($s);

        $this->artisan('diaba:billing:reconcile')->assertSuccessful();
        $this->assertSame($end, $this->periodEnd($s));
        $this->assertSame(1, PaymentProviderEvent::query()->where('provider', 'fake')->count());
    }

    public function test_a_provider_outage_does_not_stop_other_payments_and_is_retried(): void
    {
        $s = $this->pendingPayment();
        FakeBillingProvider::$remote[$s['payment']->external_reference]['state'] = 'succeeded';
        FakeBillingProvider::$failing = true;
        $this->travel(5)->minutes();

        $this->artisan('diaba:billing:reconcile')->assertFailed();
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);

        FakeBillingProvider::$failing = false;
        $this->artisan('diaba:billing:reconcile')->assertSuccessful();
        $this->assertSame(SaasInvoice::PAID, $s['invoice']->refresh()->status);
    }

    public function test_an_abandoned_payment_expires_but_a_late_success_is_still_honoured(): void
    {
        $s = $this->pendingPayment();

        $this->travel(25)->hours();
        $this->artisan('diaba:billing:reconcile')->assertSuccessful();
        $this->assertSame(SaasPayment::CANCELLED, $s['payment']->refresh()->status);
        $this->assertSame('expired', $s['payment']->failure_reason);
        $this->assertSame(SaasInvoice::OPEN, $s['invoice']->refresh()->status);

        // Le client avait en réalité payé : le webhook tardif active l'abonnement.
        $this->webhook($s['payment'])->assertOk();
        $this->assertSame(SaasInvoice::PAID, $s['invoice']->refresh()->status);
    }

    public function test_a_disabled_provider_is_never_queried_or_expired(): void
    {
        $s = $this->pendingPayment();
        FakeBillingProvider::$enabled = false;

        $this->travel(48)->hours();
        $this->artisan('diaba:billing:reconcile')->assertSuccessful();

        $this->assertSame(SaasPayment::PENDING, $s['payment']->refresh()->status);
    }

    public function test_in_production_a_real_provider_needs_the_owners_explicit_confirmation(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config(['billing.live_confirmed' => false]);
        $this->app->forgetInstance(ProviderRegistry::class);
        $this->assertNull(app(ProviderRegistry::class)->find('fake'));
        $this->assertNotContains('fake', app(ProviderRegistry::class)->available());

        config(['billing.live_confirmed' => true]);
        $this->app->forgetInstance(ProviderRegistry::class);
        $this->assertNotNull(app(ProviderRegistry::class)->find('fake'));
        $this->assertContains('fake', app(ProviderRegistry::class)->available());
    }
}
