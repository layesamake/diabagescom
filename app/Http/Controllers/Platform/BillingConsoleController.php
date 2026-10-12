<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\SaasBilling;
use App\Domain\Billing\SaasDocumentPdf;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Models\PaymentProviderEvent;
use App\Models\SaasCreditNote;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Console plateforme : factures SaaS, virements à valider, paiements à revoir
 * et rapprochement des webhooks (signatures invalides, références inconnues,
 * montants divergents).
 */
class BillingConsoleController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('platform.billing.manage');

        $tenants = Tenant::query()->pluck('display_name', 'id');

        return Inertia::render('platform/Billing', [
            'invoices' => SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->with(['plan:id,name', 'creditNotes' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
                ->latest()->limit(50)->get()
                ->map(fn (SaasInvoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'tenant' => $tenants[$i->tenant_id] ?? '?', 'plan' => $i->plan->name,
                    'cycle' => $i->billing_cycle, 'total' => $i->total, 'status' => $i->status, 'due_on' => $i->due_on->toDateString(),
                    'paid_at' => $i->paid_at?->toIso8601String(),
                    'credit_notes' => $i->creditNotes->map(fn (SaasCreditNote $n) => ['id' => $n->id, 'number' => $n->number, 'amount' => $n->amount])->values(),
                    'credited' => (int) $i->creditNotes->sum('amount'),
                ]),
            'payments' => SaasPayment::query()->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [SaasPayment::MANUAL_REVIEW, SaasPayment::REQUIRES_REVIEW])
                // Paiements déclarés par le client d'abord : ce sont ceux à vérifier.
                ->orderByRaw('declared_at IS NULL')->latest()->limit(50)->get()
                ->map(fn (SaasPayment $p) => [
                    'id' => $p->id, 'tenant' => $tenants[$p->tenant_id] ?? '?', 'provider' => $p->provider, 'status' => $p->status,
                    'amount' => $p->amount, 'reference' => $p->external_reference, 'reason' => $p->failure_reason,
                    'declared_reference' => $p->declared_reference, 'declared_at' => $p->declared_at?->toIso8601String(),
                    'has_proof' => $p->proof_path !== null,
                    'created_at' => $p->created_at?->toIso8601String(),
                ]),
            'waveNumber' => config('billing.wave_number'),
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
        $data = $request->validate(['proof_reference' => ['required', 'string', 'min:3', 'max:120']], attributes: ['proof_reference' => __('billing.fields.reference')]);

        /** @var User $user */
        $user = $request->user();
        $billing->confirmTransfer(SaasPayment::query()->withoutGlobalScope(TenantScope::class)->findOrFail($payment), (string) $data['proof_reference'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.confirmed')]);

        return back(303);
    }

    public function rejectPayment(Request $request, string $payment, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], attributes: ['reason' => __('billing.fields.reason')]);

        /** @var User $user */
        $user = $request->user();
        $billing->rejectManualPayment(SaasPayment::query()->withoutGlobalScope(TenantScope::class)->findOrFail($payment), (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.rejected')]);

        return back(303);
    }

    /** Justificatif déposé par le client (capture Wave, avis de virement), affiché dans le navigateur. */
    public function proof(string $payment): StreamedResponse
    {
        Gate::authorize('platform.billing.manage');

        /** @var SaasPayment $record */
        $record = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->findOrFail($payment);
        abort_if($record->proof_path === null || ! Storage::disk('local')->exists($record->proof_path), 404);

        return Storage::disk('local')->response($record->proof_path, basename($record->proof_path), [
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function issueCreditNote(Request $request, string $invoice, SaasBilling $billing): RedirectResponse
    {
        Gate::authorize('platform.billing.manage');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['amount' => __('billing.fields.amount'), 'reason' => __('billing.fields.reason')]);

        /** @var User $user */
        $user = $request->user();
        $note = $billing->issueCreditNote(SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->findOrFail($invoice), (int) $data['amount'], (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved.credit_note', ['number' => $note->number])]);

        return back(303);
    }

    public function receipt(string $invoice, SaasDocumentPdf $pdf): Response
    {
        Gate::authorize('platform.billing.manage');
        $record = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->findOrFail($invoice);

        return $pdf->receipt($record)->download("recu-{$record->number}.pdf");
    }

    public function creditNote(string $creditNote, SaasDocumentPdf $pdf): Response
    {
        Gate::authorize('platform.billing.manage');
        $record = SaasCreditNote::query()->withoutGlobalScope(TenantScope::class)->findOrFail($creditNote);

        return $pdf->creditNote($record)->download("avoir-{$record->number}.pdf");
    }

    /**
     * Export Excel (CSV UTF-8, séparateur « ; ») des factures et avoirs émis sur la
     * période, pour la comptabilité du propriétaire.
     */
    public function export(Request $request, AuditLog $audit): StreamedResponse
    {
        Gate::authorize('platform.billing.manage');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now()->toDateString())->startOfDay()->addDay();

        $tenants = Tenant::query()->get(['id', 'display_name', 'legal_name'])->keyBy('id');
        $invoices = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->with('plan:id,name')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')->get();
        $payments = SaasPayment::query()->withoutGlobalScope(TenantScope::class)
            ->whereIn('invoice_id', $invoices->pluck('id'))->where('status', SaasPayment::SUCCEEDED)->get()->keyBy('invoice_id');
        $notes = SaasCreditNote::query()->withoutGlobalScope(TenantScope::class)->with('invoice:id,number')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')->get();

        $audit->record('platform.billing_exported', metadata: ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()]);

        return response()->streamDownload(function () use ($invoices, $payments, $notes, $tenants): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            $row = fn (array $values) => fputcsv($out, $values, ';', '"', '');
            $row(['Type', 'Numéro', 'Date', 'Entreprise', 'Raison sociale', 'Formule', 'Facturation', 'Montant (XOF)', 'Statut', 'Échéance', 'Payée le', 'Moyen', 'Référence', 'Facture d’origine', 'Motif']);

            foreach ($invoices as $invoice) {
                $tenant = $tenants[$invoice->tenant_id] ?? null;
                $payment = $payments[$invoice->id] ?? null;
                $row([
                    'Facture', $invoice->number, $invoice->created_at?->format('d/m/Y'), $tenant?->display_name, $tenant?->legal_name,
                    $invoice->plan->name, __("billing.cycles.{$invoice->billing_cycle}"), $invoice->total, __("billing.invoice_status.{$invoice->status}"),
                    $invoice->due_on->format('d/m/Y'), $invoice->paid_at?->format('d/m/Y'),
                    $payment ? __("billing.methods.{$payment->provider}") : '', $payment ? ($payment->proof_reference ?? $payment->external_reference) : '',
                    '', $invoice->void_reason ?? '',
                ]);
            }

            foreach ($notes as $note) {
                $tenant = $tenants[$note->tenant_id] ?? null;
                $row([
                    'Avoir', $note->number, $note->created_at?->format('d/m/Y'), $tenant?->display_name, $tenant?->legal_name,
                    '', '', -$note->amount, '', '', '', '', '', $note->invoice->number, $note->reason,
                ]);
            }

            fclose($out);
        }, "facturation-{$from->toDateString()}-{$to->subDay()->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
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
