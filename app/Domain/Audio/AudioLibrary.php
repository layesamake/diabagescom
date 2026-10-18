<?php

namespace App\Domain\Audio;

use App\Models\AudioClip;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bibliothèque audio du mode simple : enregistrer, remplacer et savoir si
 * une phrase peut être lue dans la langue de l'entreprise.
 *
 * Règle centrale (cahier des charges) : un haut-parleur ne s'affiche que si
 * TOUS les segments de la phrase existent dans la langue choisie. Les
 * fichiers sont stockés par entreprise, jamais partagés entre entreprises.
 */
final class AudioLibrary
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Enregistre (ou remplace) un segment d'une phrase.
     */
    public function record(string $code, int $segment, UploadedFile $file, ?User $author = null): AudioClip
    {
        $language = $this->requireLanguage();
        $this->assertSegment($code, $segment);

        $path = $file->store("tenants/{$this->tenant->id}/audio/{$language}", 'local');

        return AudioClip::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => $code, 'language' => $language, 'segment_index' => $segment],
            ['file_path' => $path, 'duration_ms' => null, 'created_by' => $author?->id],
        );
    }

    public function delete(string $code, int $segment): void
    {
        $language = $this->requireLanguage();
        $clip = AudioClip::query()
            ->where(['tenant_id' => $this->tenant->id, 'code' => $code, 'language' => $language, 'segment_index' => $segment])
            ->first();

        if ($clip === null) {
            return;
        }

        Storage::disk('local')->delete($clip->file_path);
        $clip->delete();
    }

    /** Vrai seulement si tous les segments requis de cette phrase sont enregistrés. */
    public function isAvailable(string $code): bool
    {
        if ($this->tenant->voice_language === null) {
            return false;
        }

        $required = PhraseCatalog::requiredSegments($code);

        if ($required === []) {
            return true;
        }

        $recorded = AudioClip::query()
            ->where(['tenant_id' => $this->tenant->id, 'code' => $code, 'language' => $this->tenant->voice_language])
            ->pluck('segment_index')
            ->all();

        return array_diff($required, $recorded) === [];
    }

    /** Disponibilité de chaque code demandé, en une seule requête. */
    public function availability(array $codes): array
    {
        if ($this->tenant->voice_language === null || $codes === []) {
            return array_fill_keys($codes, false);
        }

        $recorded = AudioClip::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('language', $this->tenant->voice_language)
            ->whereIn('code', $codes)
            ->get(['code', 'segment_index'])
            ->groupBy('code')
            ->map(fn ($rows) => $rows->pluck('segment_index')->all());

        $result = [];

        foreach ($codes as $code) {
            $required = PhraseCatalog::requiredSegments($code);
            $result[$code] = array_diff($required, $recorded->get($code, []))  === [];
        }

        return $result;
    }

    /**
     * Progrès des 71 enregistrements « cœur » (Communs + Chiffres + Vendre +
     * Caisse) qui cochent l'étape 2 du guide.
     *
     * @return array{done: int, total: int, complete: bool}
     */
    public function coreProgress(): array
    {
        $core = PhraseCatalog::coreCodes();
        $availability = $this->availability($core);
        $done = count(array_filter($availability));

        return ['done' => $done, 'total' => count($core), 'complete' => $done === count($core)];
    }

    private function requireLanguage(): string
    {
        if ($this->tenant->voice_language === null) {
            throw new InvalidArgumentException('Choisissez la langue de la voix avant d\'enregistrer.');
        }

        return $this->tenant->voice_language;
    }

    private function assertSegment(string $code, int $segment): void
    {
        if (! PhraseCatalog::exists($code)) {
            throw new InvalidArgumentException("Phrase inconnue : {$code}");
        }

        if ($segment < 0 || $segment >= PhraseCatalog::segmentCount($code)) {
            throw new InvalidArgumentException("Segment invalide pour {$code} : {$segment}");
        }
    }
}
