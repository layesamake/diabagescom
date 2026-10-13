<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountingSetup;
use App\Domain\Accounting\EntryAttachments;
use App\Domain\Accounting\EntryLine;
use App\Domain\Accounting\Ledger;
use App\Models\AuditEvent;
use App\Models\JournalEntry;
use App\Models\JournalEntryAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class EntryAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function initialized(): array
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        app(AccountingSetup::class)->initialize($setup['user'], CarbonImmutable::today()->startOfYear());

        return $setup;
    }

    private function draft(array $setup): JournalEntry
    {
        return app(Ledger::class)->saveDraft(null, 'OD', CarbonImmutable::today(), 'Apport', [
            EntryLine::debit('521', 1000, 'x'), EntryLine::credit('101', 1000, 'x'),
        ], $setup['user']);
    }

    private function pdf(string $name = 'facture.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    public function test_a_voucher_is_attached_downloaded_and_removed_from_a_draft(): void
    {
        $setup = $this->initialized();
        $entry = $this->draft($setup);

        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf()])->assertRedirect();

        $attachment = JournalEntryAttachment::query()->firstOrFail();
        $this->assertSame('facture.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith("tenants/{$setup['tenant']->id}/accounting/attachments/", $attachment->path);
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'accounting.attachment_added')->exists());

        $download = $this->actingAs($setup['user'])->get(route('accounting.entries.attachments.show', [$entry->id, $attachment->id]));
        $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($setup['user'])->delete(route('accounting.entries.attachments.destroy', [$entry->id, $attachment->id]))->assertRedirect();
        $this->assertSame(0, JournalEntryAttachment::query()->count());
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_the_type_is_read_from_the_content_not_the_name(): void
    {
        $setup = $this->initialized();
        $entry = $this->draft($setup);

        $script = UploadedFile::fake()->createWithContent('facture.pdf', '<?php echo "x";');
        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $script])->assertSessionHasErrors('file');
        $this->assertSame(0, JournalEntryAttachment::query()->count());

        $big = UploadedFile::fake()->createWithContent('gros.pdf', "%PDF-1.4\n".str_repeat('a', EntryAttachments::MAX_BYTES));
        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $big])->assertSessionHasErrors('file');
        $this->assertSame(0, JournalEntryAttachment::query()->count());
    }

    public function test_a_posted_entry_accepts_vouchers_but_keeps_them(): void
    {
        $setup = $this->initialized();
        $entry = app(Ledger::class)->post('OD', CarbonImmutable::today(), 'X', [
            EntryLine::debit('521', 10, 'x'), EntryLine::credit('101', 10, 'x'),
        ], $setup['user']);

        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf()])->assertRedirect();
        $attachment = JournalEntryAttachment::query()->firstOrFail();

        $this->actingAs($setup['user'])->delete(route('accounting.entries.attachments.destroy', [$entry->id, $attachment->id]))
            ->assertSessionHasErrors();
        $this->assertSame(1, JournalEntryAttachment::query()->count());
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_the_number_of_vouchers_per_entry_is_limited(): void
    {
        $setup = $this->initialized();
        $entry = $this->draft($setup);

        for ($i = 0; $i < EntryAttachments::MAX_PER_ENTRY; $i++) {
            $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf("p{$i}.pdf")]);
        }

        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf('en-trop.pdf')])->assertSessionHasErrors('file');
        $this->assertSame(EntryAttachments::MAX_PER_ENTRY, JournalEntryAttachment::query()->count());
    }

    public function test_permissions_and_company_isolation(): void
    {
        $setup = $this->initialized();
        $entry = $this->draft($setup);
        $this->actingAs($setup['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf()]);
        $attachment = JournalEntryAttachment::query()->firstOrFail();

        // La direction consulte et télécharge, mais n'ajoute rien.
        $management = $this->member($setup, 'management');
        $this->actingAs($management['user'])->get(route('accounting.entries.attachments.show', [$entry->id, $attachment->id]))->assertOk();
        $this->actingAs($management['user'])->post(route('accounting.entries.attachments.store', $entry->id), ['file' => $this->pdf()])->assertForbidden();
        $this->actingAs($management['user'])->delete(route('accounting.entries.attachments.destroy', [$entry->id, $attachment->id]))->assertForbidden();

        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('accounting.entries.attachments.show', [$entry->id, $attachment->id]))->assertForbidden();

        // Une autre entreprise ne devine ni ne télécharge la pièce.
        $intruder = $this->initialized();
        $this->actingAs($intruder['user'])->get(route('accounting.entries.attachments.show', [$entry->id, $attachment->id]))->assertNotFound();
        $this->actingAs($intruder['user'])->delete(route('accounting.entries.attachments.destroy', [$entry->id, $attachment->id]))->assertNotFound();
    }
}
