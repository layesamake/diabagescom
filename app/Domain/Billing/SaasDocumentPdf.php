<?php

namespace App\Domain\Billing;

use App\Domain\Tenancy\TenantScope;
use App\Models\SaasCreditNote;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Reçu d'une facture d'abonnement payée, et avoir. Même gabarit A4, émis au
 * nom de DIABA GESCOM (config billing.issuer) pour l'entreprise cliente.
 */
final class SaasDocumentPdf
{
    public function receipt(SaasInvoice $invoice): DomPdf
    {
        if ($invoice->status !== SaasInvoice::PAID) {
            throw new InvalidBillingOperation(__('billing.errors.not_paid'));
        }

        /** @var SaasPayment|null $payment */
        $payment = SaasPayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('invoice_id', $invoice->id)->where('status', SaasPayment::SUCCEEDED)->first();

        return $this->render([
            'kind' => 'receipt',
            'number' => $invoice->number,
            'date' => $invoice->paid_at,
            'invoice' => $invoice,
            'lines' => $invoice->lines,
            'total' => $invoice->total,
            'payment' => $payment,
            'reason' => null,
        ], $invoice->tenant_id);
    }

    public function creditNote(SaasCreditNote $note): DomPdf
    {
        /** @var SaasInvoice $invoice */
        $invoice = SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->findOrFail($note->invoice_id);

        return $this->render([
            'kind' => 'credit_note',
            'number' => $note->number,
            'date' => $note->created_at,
            'invoice' => $invoice,
            'lines' => [['label' => __('billing.pdf.credit_line', ['number' => $invoice->number]), 'quantity' => 1, 'unit_price' => $note->amount, 'amount' => $note->amount]],
            'total' => $note->amount,
            'payment' => null,
            'reason' => $note->reason,
        ], $note->tenant_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data, string $tenantId): DomPdf
    {
        return Pdf::loadView('pdf.saas-document', [
            ...$data,
            'tenant' => Tenant::query()->findOrFail($tenantId),
            'issuer' => (array) config('billing.issuer'),
            'logo' => public_path('brand/logo-diaba-gescom-horizontal.png'),
        ])->setPaper('a4');
    }
}
