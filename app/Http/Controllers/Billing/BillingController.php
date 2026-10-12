<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\ProviderRegistry;
use App\Domain\Billing\SaasBilling;
use App\Domain\Billing\SaasDocumentPdf;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SaasCreditNote;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abonnement côté client : facture, paiement, annulation. Accessible même en
 * lecture seule ou suspendu, pour pouvoir régulariser.
 */
class BillingController extends Controller
{
    public function store(Request $request, SaasBilling $billing, TenantContext $context): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('active', true)],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'addons' => ['nullable', 'array'],
            'addons.extra_user' => ['nullable', 'integer', 'min:0', 'max:100'],
            'addons.extra_warehouse' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
        $addons = array_map('intval', array_filter((array) ($data['addons'] ?? [])));
        $invoice = $billing->createInvoice($context->tenant(), $plan, (string) $data['cycle'], $addons, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.invoice', ['number' => $invoice->number])]);

        return to_route('subscription.show');
    }

    public function void(Request $request, SaasInvoice $invoice, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $billing->voidInvoice($invoice, (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.voided')]);

        return to_route('subscription.show');
    }

    public function pay(Request $request, SaasInvoice $invoice, SaasBilling $billing, ProviderRegistry $providers): Response
    {
        Gate::authorize('organization.manage');
        $data = $request->validate([
            'provider' => ['required', Rule::in($providers->available())],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $payment = $billing->startPayment($invoice, (string) $data['provider'], $user, (string) $data['idempotency_key']);

        if ($payment->status === SaasPayment::MANUAL_REVIEW) {
            Inertia::flash('toast', ['type' => 'info', 'message' => $payment->provider === 'wave_manual'
                ? __('billing.saved.wave', ['number' => config('billing.wave_number'), 'reference' => $payment->external_reference])
                : __('billing.saved.transfer', ['reference' => $payment->external_reference])]);

            return to_route('subscription.show');
        }

        // Redirection vers la page du prestataire (hors de l'application Inertia).
        return Inertia::location((string) $payment->checkout_url);
    }

    /**
     * Le client déclare son virement ou son envoi Wave : référence de la
     * transaction et, s'il le souhaite, la capture ou le reçu (image ou PDF).
     */
    public function declare(Request $request, SaasPayment $payment, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], attributes: ['reference' => __('billing.fields.reference'), 'proof' => __('billing.fields.proof')]);

        /** @var User $user */
        $user = $request->user();
        $billing->declarePayment($payment, (string) $data['reference'], $request->file('proof'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.declared')]);

        return to_route('subscription.show');
    }

    public function receipt(SaasInvoice $invoice, SaasDocumentPdf $pdf): Response
    {
        Gate::authorize('organization.manage');

        return $pdf->receipt($invoice)->download("recu-{$invoice->number}.pdf");
    }

    public function creditNote(SaasCreditNote $creditNote, SaasDocumentPdf $pdf): Response
    {
        Gate::authorize('organization.manage');

        return $pdf->creditNote($creditNote)->download("avoir-{$creditNote->number}.pdf");
    }
}
