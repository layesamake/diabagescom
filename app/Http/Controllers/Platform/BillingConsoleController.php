<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Billing\SaasBilling;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Models\PaymentProviderEvent;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Console plateforme : factures SaaS, virements à valider, paiements à revoir
 * et rapprochement des webhooks (signatures invalides, références inconnues,
 * montants divergents).
 */
class BillingConsoleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('platform.billing.manage');

        $tenants = Tenant::query()->pluck('display_name', 'id');

        return Inertia::render('platform/Billing', [
            'invoices' => SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->with('plan:id,name')
                ->latest()->limit(50)->get()
                ->map(fn (SaasInvoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'tenant' => $tenants[$i->tenant_id] ?? '?', 'plan' => $i->plan->name,
                    'cycle' => $i->billing_cycle, 'total' => $i->total, 'status' => $i->status, 'due_on' => $i->due_on->toDateString(),
                    'paid_at' => $i->paid_at?->toIso8601String(),
                ]),
            'payments' => SaasPayment::query()->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [SaasPayment::MANUAL_REVIEW, SaasPayment::REQUIRES_REVIEW])
                ->latest()->limit(50)->get()
                ->map(fn (SaasPayment $p) => [
                    'id' => $p->id, 'tenant' => $tenants[$p->tenant_id] ?? '?', 'provider' => $p->provider, 'status' => $p->status,
                    'amount' => $p->amount, 'reference' => $p->external_reference, 'reason' => $p->failure_reason,
                    'created_at' => $p->created_at?->toIso8601String(),
                ]),
            'events' => PaymentProviderEvent::query()->where('status', '<>', 'processed')
                ->orWhereNotNull('error')->latest('created_at')->limit(50)->get()
                ->map(fn (PaymentProviderEvent $e) => [
                    'id' => $e->id, 'provider' => $e->provider, 'event' => $e->external_event_id, 'type' => $e->event_type,
                    'signature_valid' => $e->signature_valid, 'status' => $e->status, 'error' => $e->error,
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function confirmTransfer(Request $request, string $payment, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');
        $data = $request->validate(['proof_reference' => ['required', 'string', 'min:3', 'max:120']], attributes: ['proof_reference' => 'référence du justificatif']);

        /** @var User $user */
        $user = $request->user();
        $billing->confirmTransfer(SaasPayment::query()->withoutGlobalScope(TenantScope::class)->findOrFail($payment), (string) $data['proof_reference'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.confirmed')]);

        return back(303);
    }

    public function voidInvoice(Request $request, string $invoice, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $billing->voidInvoice(SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->findOrFail($invoice), (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.voided')]);

        return back(303);
    }
}
