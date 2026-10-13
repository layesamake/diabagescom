<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Models\JournalEntry;
use App\Models\JournalEntryAttachment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pièces justificatives des écritures (PDF, JPEG, PNG).
 *
 * Le type est lu dans le contenu du fichier, jamais dans l'extension ni dans
 * l'en-tête envoyé par le navigateur. Stockage privé rangé par entreprise
 * (inclus dans la sauvegarde) ; téléchargement uniquement par une route
 * autorisée.
 */
final class EntryAttachments
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_PER_ENTRY = 10;

    private const array TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    private const string DISK = 'local';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    public function add(JournalEntry $entry, UploadedFile $file, User $actor): JournalEntryAttachment
    {
        $path = (string) $file->getRealPath();
        $mime = $path !== '' ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';

        if (! isset(self::TYPES[$mime]) || (int) $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('accounting.attachments.invalid')]);
        }

        $stored = "tenants/{$this->context->tenantId()}/accounting/attachments/".Str::uuid7().'.'.self::TYPES[$mime];

        return DB::transaction(function () use ($entry, $file, $actor, $mime, $path, $stored): JournalEntryAttachment {
            // Verrou sur l'écriture : deux envois simultanés ne dépassent pas la limite.
            JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (JournalEntryAttachment::query()->where('entry_id', $entry->id)->count() >= self::MAX_PER_ENTRY) {
                throw ValidationException::withMessages(['file' => __('accounting.attachments.limit', ['max' => self::MAX_PER_ENTRY])]);
            }

            Storage::disk(self::DISK)->put($stored, (string) file_get_contents($path));

            $attachment = JournalEntryAttachment::query()->create([
                'entry_id' => $entry->id,
                'original_name' => $this->cleanName($file->getClientOriginalName()),
                'path' => $stored,
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'sha256' => (string) hash_file('sha256', $path),
                'uploaded_by' => $actor->id,
            ]);
            $this->audit->record('accounting.attachment_added', $entry, after: ['name' => $attachment->original_name, 'sha256' => $attachment->sha256], actor: $actor);

            return $attachment;
        });
    }

    public function remove(JournalEntry $entry, JournalEntryAttachment $attachment, User $actor): void
    {
        if ($entry->status !== JournalEntry::DRAFT) {
            throw new InvalidAccountingOperation(__('accounting.attachments.draft_only'));
        }

        DB::transaction(function () use ($entry, $attachment, $actor): void {
            $attachment->delete();
            $this->audit->record('accounting.attachment_removed', $entry, before: ['name' => $attachment->original_name, 'sha256' => $attachment->sha256], actor: $actor);
        });
        Storage::disk(self::DISK)->delete($attachment->path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Nom affiché : sans chemin ni caractères de contrôle, borné en longueur. */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', '', basename(str_replace('\\', '/', $name))));

        return Str::limit($name === '' ? 'piece' : $name, 160, '');
    }
}
