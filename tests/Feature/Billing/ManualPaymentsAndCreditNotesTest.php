<?php

namespace Tests\Feature\Billing;

use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasCreditNote;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualPaymentsAndCreditNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.wave_number' => '+221 77 000 00 00']);
        Plan::query()->where('code', 'business')->update(['price_monthly' => 25000, 'price_yearly' => 250000]);
        Storage::fake('local');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    /** @return array{0: SaasInvoice, 1: SaasPayment} */
    private function manualPayment(array $setup, string $provider = 'wave_manual'): array
    {
        $this->actingAs($setup['user'])->post(route('billing.invoices.store'), ['plan' => 'business', 'cycle' => 'monthly'])->assertRedirect();
        $invoice = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->latest()->firstOrFail();
        $this->actingAs($setup['user'])->post(route('billing.invoices.pay', $invoice->id), ['provider' => $provider, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();

        return [$invoice, SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->latest()->firstOrFail()];
    }

    private function paidInvoice(array $setup): SaasInvoice
    {
        [$invoice, $payment] = $this->manualPayment($setup, 'transfer');
        $this->actingAs($this->superAdmin())->post(route('platform.billing.payments.confirm', $payment->id), ['proof_reference' => 'BQ-1'])->assertRedirect();

        return $invoice->refresh();
    }

    public function test_wave_is_offered_only_when_a_number_is_configured(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($setup['user'])->get(route('subscription.show'))
            ->assertInertia(fn ($page) => $page->where('providers', fn ($p) => collect($p)->contains('wave_manual'))->where('waveNumber', '+221 77 000 00 00'));

        config(['billing.wave_number' => null]);
        $this->actingAs($setup['user'])->get(route('subscription.show'))
            ->assertInertia(fn ($page) => $page->where('providers', fn ($p) => ! collect($p)->contains('wave_manual')));
    }

    public function test_the_client_declares_a_wave_payment_and_the_super_admin_validates_it(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        [$invoice, $payment] = $this->manualPayment($setup);
        $this->assertSame(SaasPayment::MANUAL_REVIEW, $payment->status);
        $this->assertStringStartsWith('WAV-', $payment->external_reference);

        $this->actingAs($setup['user'])->post(route('billing.payments.declare', $payment->id), [
            'reference' => 'WAVE-TX-123456',
            'proof' => UploadedFile::fake()->image('capture.png'),
        ])->assertRedirect(route('subscription.show'));

        $payment->refresh();
        $this->assertSame('WAVE-TX-123456', $payment->declared_reference);
        $this->assertNotNull($payment->declared_at);
        Storage::disk('local')->assertExists((string) $payment->proof_path);
        $this->assertStringStartsWith("saas-proofs/{$setup['tenant']->id}/", (string) $payment->proof_path);
        // Une déclaration n'active rien : seule la validation du propriétaire compte.
        $this->assertSame(Subscription::TRIALING, Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole()->status);

        // Le client ne peut ni voir les justificatifs côté console ni valider.
        $this->actingAs($setup['user'])->get(route('platform.billing.payments.proof', $payment->id))->assertForbidden();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('platform.billing.index'))
            ->assertInertia(fn ($page) => $page->where('payments.0.declared_reference', 'WAVE-TX-123456')->where('payments.0.has_proof', true));
        $this->actingAs($admin)->get(route('platform.billing.payments.proof', $payment->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($admin)->post(route('platform.billing.payments.confirm', $payment->id), ['proof_reference' => 'WAVE-TX-123456'])->assertRedirect();

        $this->assertSame(SaasInvoice::PAID, $invoice->refresh()->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'billing.payment_declared')->count());

        // Une fois validé, le paiement ne peut plus être re-déclaré.
        $this->actingAs($setup['user'])->post(route('billing.payments.declare', $payment->id), ['reference' => 'AUTRE-REF'])->assertSessionHasErrors('business');
    }

    public function test_declarations_validate_the_file_and_stay_within_the_company(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        [, $payment] = $this->manualPayment($setup);

        $this->actingAs($setup['user'])->post(route('billing.payments.declare', $payment->id), [
            'reference' => 'TX-1', 'proof' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('proof');

        $other = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($other['user'])->post(route('billing.payments.declare', $payment->id), ['reference' => 'TX-1'])->assertNotFound();
        $this->assertNull($payment->refresh()->declared_at);
    }

    public function test_the_super_admin_can_reject_a_manual_payment(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        [$invoice, $payment] = $this->manualPayment($setup, 'transfer');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('platform.billing.payments.reject', $payment->id), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('platform.billing.payments.reject', $payment->id), ['reason' => 'Aucun virement reçu sur le compte'])->assertRedirect();

        $payment->refresh();
        $this->assertSame(SaasPayment::CANCELLED, $payment->status);
        $this->assertSame('Aucun virement reçu sur le compte', $payment->failure_reason);
        $this->assertSame(SaasInvoice::OPEN, $invoice->refresh()->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'billing.payment_rejected')->count());
    }

    public function test_receipts_exist_only_for_paid_invoices_and_only_for_their_company(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        [$open] = $this->manualPayment($setup, 'transfer');
        $this->actingAs($setup['user'])->get(route('billing.invoices.receipt', $open->id))->assertSessionHasErrors('business');

        $invoice = $this->paidInvoice($setup);
        $this->actingAs($setup['user'])->get(route('billing.invoices.receipt', $invoice->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->superAdmin())->get(route('platform.billing.invoices.receipt', $invoice->id))->assertOk();

        $other = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($other['user'])->get(route('billing.invoices.receipt', $invoice->id))->assertNotFound();
    }

    public function test_credit_notes_are_capped_by_the_invoice_total_and_downloadable(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->paidInvoice($setup);
        $admin = $this->superAdmin();

        $this->actingAs($setup['user'])->post(route('platform.billing.credit-notes.store', $invoice->id), ['amount' => 1000, 'reason' => 'Geste commercial'])->assertForbidden();

        $this->actingAs($admin)->post(route('platform.billing.credit-notes.store', $invoice->id), ['amount' => 10000, 'reason' => 'Geste commercial'])->assertRedirect();
        $this->actingAs($admin)->post(route('platform.billing.credit-notes.store', $invoice->id), ['amount' => 15001, 'reason' => 'Trop élevé'])->assertSessionHasErrors('business');
        $this->actingAs($admin)->post(route('platform.billing.credit-notes.store', $invoice->id), ['amount' => 15000, 'reason' => 'Solde remboursé'])->assertRedirect();

        $notes = SaasCreditNote::query()->withoutGlobalScope(TenantScope::class)->orderBy('created_at')->get();
        $this->assertCount(2, $notes);
        $this->assertMatchesRegularExpression('/^AV-\d{4}-\d{6}$/', $notes[0]->number);
        $this->assertSame($setup['tenant']->id, $notes[0]->tenant_id);
        $this->assertSame(2, AuditEvent::query()->where('action', 'billing.credit_note_issued')->count());

        $this->actingAs($setup['user'])->get(route('billing.credit-notes.show', $notes[0]->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($setup['user'])->get(route('subscription.show'))
            ->assertInertia(fn ($page) => $page->where('invoices.0.credit_notes.0.number', $notes[0]->number));

        $other = $this->createTenantWithMember(plan: 'business');
        $this->actingAs($other['user'])->get(route('billing.credit-notes.show', $notes[0]->id))->assertNotFound();

        // Pas d'avoir sur une facture impayée.
        [$open] = $this->manualPayment($other, 'transfer');
        $this->actingAs($admin)->post(route('platform.billing.credit-notes.store', $open->id), ['amount' => 100, 'reason' => 'Geste commercial'])->assertSessionHasErrors('business');
    }

    public function test_the_billing_export_lists_invoices_and_credit_notes(): void
    {
        $setup = $this->createTenantWithMember(plan: 'business');
        $invoice = $this->paidInvoice($setup);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('platform.billing.credit-notes.store', $invoice->id), ['amount' => 5000, 'reason' => 'Geste commercial'])->assertRedirect();

        $this->actingAs($setup['user'])->get(route('platform.billing.export'))->assertForbidden();

        $response = $this->actingAs($admin)->get(route('platform.billing.export', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($invoice->number, $csv);
        $this->assertStringContainsString('Virement bancaire', $csv);
        $this->assertStringContainsString('BQ-1', $csv);
        $this->assertStringContainsString(';-5000;', $csv);
        $this->assertSame(1, AuditEvent::query()->where('action', 'platform.billing_exported')->count());

        $this->actingAs($admin)->get(route('platform.billing.export', ['from' => '2026-02-10', 'to' => '2026-02-01']))->assertSessionHasErrors('to');
    }
}
