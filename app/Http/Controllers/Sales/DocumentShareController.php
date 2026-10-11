<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\SalesDocumentPdf;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Mail\SalesDocumentMail;
use App\Models\SalesDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Partage d'un document de vente (WhatsApp, email, lien) : un lien signé et
 * temporaire donne accès au seul PDF du document, sans compte. Chaque partage
 * est audité ; les brouillons et documents annulés ne se partagent pas.
 */
class DocumentShareController extends Controller
{
    public const int LINK_DAYS = 30;

    /**
     * Crée un lien de partage (affiché, copié ou envoyé par WhatsApp).
     */
    public function link(Request $request, SalesDocument $document, StockAccess $access, AuditLog $audit): JsonResponse
    {
        $this->authorizeShare($document, $access);
        $data = $request->validate([
            'format' => ['required', Rule::in(SalesDocumentPdf::FORMATS)],
            'channel' => ['required', Rule::in(['whatsapp', 'link'])],
        ]);

        $expires = now()->addDays(self::LINK_DAYS);
        $audit->record('sales.document_shared', $document, metadata: ['channel' => $data['channel'], 'format' => $data['format'], 'expires_at' => $expires->toIso8601String()]);

        return response()->json([
            'url' => URL::temporarySignedRoute('documents.shared', $expires, ['document' => $document->id, 'format' => $data['format']]),
            'expires_at' => $expires->toDateString(),
        ]);
    }

    /**
     * Envoie le PDF en pièce jointe (file d'attente ; le lien est aussi dans le message).
     */
    public function email(Request $request, SalesDocument $document, StockAccess $access, TenantContext $context, AuditLog $audit): JsonResponse
    {
        $this->authorizeShare($document, $access);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'format' => ['required', Rule::in(SalesDocumentPdf::FORMATS)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $url = URL::temporarySignedRoute('documents.shared', now()->addDays(self::LINK_DAYS), ['document' => $document->id, 'format' => $data['format']]);
        Mail::to($data['email'])->queue(new SalesDocumentMail($context->tenantId(), $document->id, $data['format'], $data['message'] ?? null, $url));
        $audit->record('sales.document_shared', $document, metadata: ['channel' => 'email', 'format' => $data['format'], 'to' => $data['email']]);

        return response()->json(['sent' => true]);
    }

    /**
     * Accès public par lien signé : uniquement le PDF du document visé.
     */
    public function show(Request $request, string $document, TenantContext $context, SalesDocumentPdf $renderer): HttpResponse
    {
        // Pas de session ni de contexte : on retrouve le document par son identifiant
        // signé, puis tout le rendu se fait dans le contexte de son entreprise.
        $found = SalesDocument::query()->withoutGlobalScope(TenantScope::class)->with('tenant')->findOrFail($document);
        abort_if($found->isDraft() || $found->number === null || $found->status === SalesDocument::CANCELLED, 404);
        $format = SalesDocumentPdf::format($request->query('format'));

        return $context->runAs($found->tenant, fn () => $renderer->render($found, $found->tenant, $format)
            ->stream($renderer->filename($found, $format)))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-store');
    }

    private function authorizeShare(SalesDocument $document, StockAccess $access): void
    {
        Gate::authorize('sale.view');
        abort_unless(in_array($document->warehouse_id, $access->accessibleIds(), true), 404);
        abort_if($document->isDraft() || $document->number === null || $document->status === SalesDocument::CANCELLED, 422);
    }
}
