<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\SaasBilling;
use App\Domain\Billing\SandboxProvider;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Models\SaasPayment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page de paiement du prestataire de démonstration : simule la décision du
 * prestataire et lui fait appeler NOTRE webhook avec une signature valide.
 * Indisponible quand le bac à sable est désactivé (production).
 */
class SandboxCheckoutController extends Controller
{
    public function show(Request $request, string $reference, SandboxProvider $sandbox): Response
    {
        $payment = $this->payment($request, $reference, $sandbox);
        $payment->load(['invoice' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)]);

        return Inertia::render('billing/SandboxCheckout', [
            'payment' => [
                'reference' => $payment->external_reference,
                'amount' => $payment->amount,
                'status' => $payment->status,
                'invoice' => $payment->invoice->number,
            ],
        ]);
    }

    public function decide(Request $request, string $reference, SandboxProvider $sandbox, SaasBilling $billing): RedirectResponse
    {
        $payment = $this->payment($request, $reference, $sandbox);
        $outcome = (string) $request->validate(['outcome' => ['required', 'in:succeeded,failed']])['outcome'];

        // Événement déterministe : renvoyer la même décision rejoue le même événement (dédupliqué).
        $body = (string) json_encode([
            'id' => "evt_{$payment->id}_{$outcome}",
            'type' => "payment.{$outcome}",
            'data' => ['reference' => $payment->external_reference, 'amount' => $payment->amount],
        ]);
        $webhook = Request::create('/webhooks/billing/sandbox', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $webhook->headers->set('X-Diaba-Signature', $sandbox->sign($body));

        [, $status] = $billing->handleWebhook('sandbox', $webhook);
        $payment->refresh();

        Inertia::flash('toast', [
            'type' => $payment->status === SaasPayment::SUCCEEDED ? 'success' : 'info',
            'message' => $payment->status === SaasPayment::SUCCEEDED ? __('billing.saved.confirmed') : __('billing.saved.sandbox_sent', ['status' => $status]),
        ]);

        return to_route('subscription.show');
    }

    private function payment(Request $request, string $reference, SandboxProvider $sandbox): SaasPayment
    {
        abort_unless($sandbox->isEnabled(), 404);
        /** @var User $user */
        $user = $request->user();

        /** @var SaasPayment */
        return SaasPayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('provider', 'sandbox')->where('external_reference', $reference)
            ->where('created_by', $user->id)->firstOrFail();
    }
}
