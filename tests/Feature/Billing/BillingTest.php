<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingReminders;
use App\Domain\Billing\SandboxProvider;
use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\PaymentProviderEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SaasInvoiceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.providers.sandbox.enabled' => true, 'billing.providers.sandbox.secret' => 'test-secret-'.Str::random(16)]);
        Plan::query()->where('code', 'business')->update(['price_monthly' => 25000, 'price_yearly' => 250000]);
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

    private function invoiceFor(array $setup, string $cycle = 'monthly'): SaasInvoice
    {
        $this->actingAs($setup['user'])->post(route('billing.invoices.store'), ['plan' => 'business', 'cycle' => $cycle])->assertRedirect();

        return SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->latest()->firstOrFail();
    }

    private function sandboxPayment(array $setup, SaasInvoice $invoice): SaasPayment
    {
        $this->actingAs($setup['user'])->post(route('billing.invoices.pay', $invoice->id), [
            'provider' => 'sandbox', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        return SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->latest()->firstOrFail();
    }

    private function webhook(string $eventId, string $type, string $reference, int $amount, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode(['id' => $eventId, 'type' => $type, 'data' => ['reference' => $reference, 'amount' => $amount]]);

        return $this->call('POST', route('webhooks.billing', 'sandbox'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DIABA_SIGNATURE' => $signature ?? app(SandboxProvider::class)->sign($body),
        ], $body);
    }

    public function test_an_invoice_needs_a_price_and_only_one_can_be_open(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');

        $this->actingAs($setup['user'])->post(route('billing.invoices.store'), ['plan' => 'essential', 'cycle' => 'monthly'])
            ->assertSessionHasErrors('business');

        $invoice = $this->invoiceFor($setup);
        $this->assertSame('DG-'.now()->year.'-'.str_pad((string) ((int) substr($invoice->number, -6)), 6, '0', STR_PAD_LEFT), $invoice->number);
        $this->assertSame(25000, $invoice->total);

        $this->actingAs($setup['user'])->post(route('billing.invoices.store'), ['plan' => 'business', 'cycle' => 'monthly'])
            ->assertSessionHasErrors('business');
        $this->assertSame(1, SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_signed_webhook_activates_once_and_replays_change_nothing(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->invoiceFor($setup);
        $payment = $this->sandboxPayment($setup, $invoice);

        // Retour du navigateur seul : aucun effet.
        $this->actingAs($setup['user'])->get(route('billing.sandbox.show', $payment->external_reference))->assertOk();
        $this->assertSame(Subscription::TRIALING, $this->subscriptionOf($setup)->status);

        $this->webhook('evt_1', 'payment.succeeded', $payment->external_reference, 25000)->assertOk()->assertJson(['status' => 'processed']);
        $subscription = $this->subscriptionOf($setup);
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertSame(now()->addMonth()->toIso8601String(), $subscription->current_period_end?->toIso8601String());
        $this->assertSame(SaasInvoice::PAID, $invoice->refresh()->status);

        // Rejeu du même événement, puis nouvel événement pour le même paiement.
        $this->webhook('evt_1', 'payment.succeeded', $payment->external_reference, 25000)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->webhook('evt_2', 'payment.succeeded', $payment->external_reference, 25000)->assertOk();

        $this->assertSame(now()->addMonth()->toIso8601String(), $this->subscriptionOf($setup)->current_period_end?->toIso8601String(), 'Extended once.');
        $this->assertSame(1, SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('status', SaasPayment::SUCCEEDED)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'subscription.paid')->count());
    }

    public function test_invalid_signatures_and_wrong_amounts_never_change_the_subscription(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->invoiceFor($setup);
        $payment = $this->sandboxPayment($setup, $invoice);

        $this->webhook('evt_forged', 'payment.succeeded', $payment->external_reference, 25000, 't='.time().',v1='.str_repeat('a', 64))->assertStatus(401);
        $old = time() - 3600;
        $body = (string) json_encode(['id' => 'evt_old', 'type' => 'payment.succeeded', 'data' => ['reference' => $payment->external_reference, 'amount' => 25000]]);
        $this->webhook('evt_old', 'payment.succeeded', $payment->external_reference, 25000, app(SandboxProvider::class)->sign($body, $old))->assertStatus(401);
        $this->webhook('evt_cheap', 'payment.succeeded', $payment->external_reference, 100)->assertOk();

        $this->assertSame(Subscription::TRIALING, $this->subscriptionOf($setup)->status);
        $this->assertSame(SaasPayment::REQUIRES_REVIEW, $payment->refresh()->status);
        $this->assertSame(SaasInvoice::OPEN, $invoice->refresh()->status);
        $this->assertSame(2, PaymentProviderEvent::query()->where('signature_valid', false)->count());
    }

    public function test_bank_transfer_is_activated_only_by_the_super_admin_with_a_proof(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->invoiceFor($setup, 'yearly');
        $this->actingAs($setup['user'])->post(route('billing.invoices.pay', $invoice->id), ['provider' => 'transfer', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $payment = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame(SaasPayment::MANUAL_REVIEW, $payment->status);
        $this->assertStringStartsWith('VIR-'.$invoice->number, $payment->external_reference);

        // Le client ne peut pas valider lui-même son virement.
        $this->actingAs($setup['user'])->post(route('platform.billing.payments.confirm', $payment->id), ['proof_reference' => 'X'])->assertForbidden();
        $this->assertSame(Subscription::TRIALING, $this->subscriptionOf($setup)->status);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('platform.billing.index'))->assertOk();
        $this->actingAs($admin)->post(route('platform.billing.payments.confirm', $payment->id), ['proof_reference' => 'BQ-2026-778'])->assertRedirect();
        $this->actingAs($admin)->post(route('platform.billing.payments.confirm', $payment->id), ['proof_reference' => 'BQ-2026-778'])->assertRedirect();

        $subscription = $this->subscriptionOf($setup);
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertSame('yearly', $subscription->billing_cycle);
        $this->assertSame(1, AuditEvent::query()->where('action', 'subscription.paid')->count());
        $this->assertSame('BQ-2026-778', $payment->refresh()->proof_reference);
    }

    public function test_a_second_payment_of_a_paid_invoice_goes_to_review_without_extending(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->invoiceFor($setup);
        $first = $this->sandboxPayment($setup, $invoice);
        $this->sandboxPayment($setup, $invoice);
        $second = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->whereKeyNot($first->id)->sole();

        $this->webhook('evt_a', 'payment.succeeded', $first->external_reference, 25000)->assertOk();
        $this->webhook('evt_b', 'payment.succeeded', $second->external_reference, 25000)->assertOk();

        $this->assertSame(SaasPayment::REQUIRES_REVIEW, $second->refresh()->status);
        $this->assertSame(now()->addMonth()->toIso8601String(), $this->subscriptionOf($setup)->current_period_end?->toIso8601String());
    }

    public function test_read_only_companies_can_still_pay_and_renewals_keep_remaining_days(): void
    {
        Notification::fake();
        $this->freezeTime();
        $setup = $this->createTenantWithMember(plan: 'business');
        $this->travel(12)->days(); // essai terminé : lecture seule

        $invoice = $this->invoiceFor($setup);
        $payment = $this->sandboxPayment($setup, $invoice);
        $this->webhook('evt_ro', 'payment.succeeded', $payment->external_reference, 25000)->assertOk();
        $end = $this->subscriptionOf($setup)->current_period_end;
        $this->assertSame(Subscription::ACTIVE, $this->subscriptionOf($setup)->status);

        // 5 jours avant la fin : facture de renouvellement, une seule fois.
        $this->travelTo($end->subDays(5));
        app(BillingReminders::class)->run();
        app(BillingReminders::class)->run();
        $renewal = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('status', SaasInvoice::OPEN)->sole();
        Notification::assertSentTo($setup['user'], SaasInvoiceNotification::class);

        $renewalPayment = $this->sandboxPayment($setup, $renewal);
        $this->webhook('evt_renew', 'payment.succeeded', $renewalPayment->external_reference, 25000)->assertOk();
        $this->assertSame($end->addMonth()->toIso8601String(), $this->subscriptionOf($setup)->current_period_end?->toIso8601String(), 'Early renewal loses no day.');
    }

    public function test_support_access_is_justified_time_limited_and_visible_to_the_company(): void
    {
        $setup = $this->createTenantWithMember();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('platform.support.show', $setup['tenant']->id))->assertSessionHasErrors('business');
        $this->actingAs($admin)->post(route('platform.support.grant', $setup['tenant']->id), ['reason' => 'Court', 'minutes' => 30])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('platform.support.grant', $setup['tenant']->id), ['reason' => 'Ticket 42 : erreur de caisse', 'minutes' => 30])->assertRedirect();
        $this->actingAs($admin)->get(route('platform.support.show', $setup['tenant']->id))->assertOk();

        $this->assertTrue(AuditEvent::query()->where('tenant_id', $setup['tenant']->id)->where('action', 'support.access_granted')->exists());
        $this->assertTrue(AuditEvent::query()->where('tenant_id', $setup['tenant']->id)->where('action', 'support.viewed')->exists());

        $this->travel(31)->minutes();
        $this->actingAs($admin)->get(route('platform.support.show', $setup['tenant']->id))->assertSessionHasErrors('business');

        // Un administrateur d'entreprise n'a pas accès à la console d'assistance.
        $this->actingAs($setup['user'])->post(route('platform.support.grant', $setup['tenant']->id), ['reason' => 'Je veux voir autre chose', 'minutes' => 30])->assertForbidden();
    }
}
