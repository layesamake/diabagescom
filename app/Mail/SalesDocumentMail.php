<?php

namespace App\Mail;

use App\Domain\Sales\SalesDocumentPdf;
use App\Domain\Tenancy\TenantContext;
use App\Models\SalesDocument;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Document de vente envoyé par email (PDF joint + lien). Mis en file d'attente
 * avec de simples identifiants : le rendu se fait dans le contexte de l'entreprise.
 */
class SalesDocumentMail extends Mailable implements ShouldQueue
{
    use Queueable;

    private ?SalesDocument $document = null;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentId,
        public readonly string $format,
        public readonly ?string $note,
        public readonly string $url,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $tenant = $this->tenant();

        return new Envelope(
            subject: __('sales.share.mail_subject', ['type' => __("sales.types.{$this->document()->type}"), 'number' => $this->document()->number, 'company' => $tenant->display_name]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.sales-document', with: [
            'company' => $this->tenant()->display_name,
            'type' => __("sales.types.{$this->document()->type}"),
            'number' => $this->document()->number,
            'total' => $this->document()->total,
            'note' => $this->note,
            'url' => $this->url,
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $renderer = app(SalesDocumentPdf::class);
        $document = $this->document();
        $pdf = app(TenantContext::class)->runAs($this->tenant(), fn () => $renderer->render($document, $this->tenant(), $this->format)->output());

        return [Attachment::fromData(fn () => $pdf, $renderer->filename($document, $this->format))->withMime('application/pdf')];
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    private function document(): SalesDocument
    {
        return $this->document ??= app(TenantContext::class)->runAs($this->tenant(), fn () => SalesDocument::query()->findOrFail($this->documentId));
    }
}
