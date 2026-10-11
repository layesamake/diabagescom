<?php

namespace Tests\Feature\Sales;

use App\Domain\Tenancy\TenantContext;
use App\Mail\SalesDocumentMail;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Partage d'un document de vente : lien signé limité au seul PDF, email en file
 * d'attente, audit ; aucun accès sans signature, aux brouillons ni hors périmètre.
 */
class DocumentShareTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function invoice(): array
    {
        $setup = $this->createTenantWithMember();
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '10', 1000]]);
        $session = $this->openSession($setup, 0);
        $invoice = $this->sell($setup, $session, [[$rice->id, '1']], [['cash', 2000]]);

        return [$setup, $invoice];
    }

    public function test_a_signed_link_gives_the_pdf_without_an_account_and_is_audited(): void
    {
        [$setup, $invoice] = $this->invoice();

        $url = $this->actingAs($setup['user'])
            ->postJson(route('sales.share', $invoice), ['format' => 'receipt', 'channel' => 'whatsapp'])
            ->assertOk()->json('url');

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'sales.document_shared')->count());

        auth()->logout();
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Signature absente ou altérée : refusé.
        $this->get(route('documents.shared', ['document' => $invoice->id, 'format' => 'receipt']))->assertForbidden();
        $this->get(str_replace('format=receipt', 'format=a4', $url))->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        [, $invoice] = $this->invoice();
        $url = URL::temporarySignedRoute('documents.shared', now()->subMinute(), ['document' => $invoice->id, 'format' => 'a4']);

        $this->get($url)->assertForbidden();
    }

    public function test_email_is_queued_with_the_document_and_audited(): void
    {
        Mail::fake();
        [$setup, $invoice] = $this->invoice();

        $this->actingAs($setup['user'])
            ->postJson(route('sales.email', $invoice), ['email' => 'client@example.sn', 'format' => 'a4', 'message' => 'Merci'])
            ->assertOk();

        Mail::assertQueued(SalesDocumentMail::class, fn (SalesDocumentMail $mail) => $mail->hasTo('client@example.sn')
            && $mail->documentId === $invoice->id && str_contains($mail->url, '/d/'));
        $this->assertSame('email', AuditEvent::query()->withoutGlobalScopes()->where('action', 'sales.document_shared')->value('metadata')['channel']);

        $this->actingAs($setup['user'])->postJson(route('sales.email', $invoice), ['email' => 'pas-un-email', 'format' => 'a4'])
            ->assertUnprocessable();
    }

    public function test_the_mail_renders_with_its_pdf_attachment(): void
    {
        [$setup, $invoice] = $this->invoice();
        $mail = new SalesDocumentMail($setup['tenant']->id, $invoice->id, 'a4', 'Merci', 'https://example.test/d/x');

        $mail->assertSeeInHtml($invoice->number);
        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame("{$invoice->number}.pdf", $attachments[0]->as);
    }

    public function test_another_tenants_document_cannot_be_shared(): void
    {
        [$setup, $invoice] = $this->invoice();
        $other = $this->createTenantWithMember();

        $this->actingAs($other['user'])->postJson(route('sales.share', $invoice), ['format' => 'a4', 'channel' => 'link'])->assertNotFound();
    }

    public function test_the_thermal_ticket_prints_at_58_or_80_mm(): void
    {
        [$setup, $invoice] = $this->invoice();
        $this->actingAs($setup['user']);

        $this->get(route('sales.ticket', ['document' => $invoice, 'width' => 58, 'print' => 1]))->assertOk()
            ->assertSee('size: 58mm auto', false)->assertSee($invoice->number)->assertSee('window.print()', false);
        // Largeur inconnue : 80 mm par défaut, sans impression automatique.
        $this->get(route('sales.ticket', ['document' => $invoice, 'width' => 99]))->assertOk()
            ->assertSee('size: 80mm auto', false)->assertDontSee("addEventListener('load'", false);

        foreach (['receipt', 'receipt_58', 'a4'] as $format) {
            $this->get(route('sales.pdf', ['document' => $invoice, 'format' => $format]))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
    }
}
