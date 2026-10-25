<?php

namespace App\Domain\Audio;

use App\Models\AudioClip;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ['code' => $code, 'language' => $language, 'segment_index' => $segment],
            ['file_path' => $path, 'duration_ms' => null, 'created_by' => $author?->id],
        );
    }

    public function delete(string $code, int $segment): void
    {
        $language = $this->requireLanguage();
        $clip = AudioClip::query()
            ->where(['code' => $code, 'language' => $language, 'segment_index' => $segment])
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

    /** Disponibilité de chaque code demandé, en une seule requête.
     *
     * @param  list<string>  $codes
     * @return array<string, bool>
     */
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
            $result[$code] = array_diff($required, $recorded->get($code, [])) === [];
        }

        return $result;
    }

    /**
     * Phrases que le haut-parleur peut vraiment jouer (règle centrale : pas
     * d'enregistrement complet, pas d'icône). Une phrase à montant, nombre ou
     * heure exige aussi tous les mots de chiffres ; une phrase avec le nom d'un
     * article ou d'un client n'est pas jouable tant que ces noms ne sont pas
     * enregistrés au micro.
     *
     * @return list<string>
     */
    public function playableCodes(): array
    {
        $codes = array_keys(PhraseCatalog::all());
        $availability = $this->availability($codes);
        $digitsComplete = ! in_array(
            false,
            array_map(fn (string $code): bool => $availability[$code] ?? false, PhraseCatalog::codesInGroup('Chiffres')),
            true,
        );

        return array_values(array_filter($codes, function (string $code) use ($availability, $digitsComplete): bool {
            $tokens = PhraseCatalog::tokens($code);

            return ($availability[$code] ?? false)
                && array_intersect($tokens, [PhraseCatalog::TOKEN_ARTICLE, PhraseCatalog::TOKEN_CLIENT]) === []
                && ($tokens === [] || $digitsComplete);
        }));
    }

    /**
     * Identifiants des morceaux à jouer, dans l'ordre, pour lire une phrase.
     * Les variables sont dites avec les mots de chiffres (NumberWords) : montant
     * et nombre en entiers, heure au format « HH:MM ».
     *
     * Renvoie null si la phrase est inconnue, si une valeur manque ou est
     * invalide, ou si un morceau n'est pas enregistré : jamais de lecture partielle.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>|null
     */
    public function playlist(string $code, array $values = []): ?array
    {
        if (! PhraseCatalog::exists($code) || $this->tenant->voice_language === null) {
            return null;
        }

        $tokens = PhraseCatalog::tokens($code);
        $required = PhraseCatalog::requiredSegments($code);
        $parts = [];

        for ($segment = 0; $segment < PhraseCatalog::segmentCount($code); $segment++) {
            if (in_array($segment, $required, true)) {
                $parts[] = [$code, $segment];
            }

            if (isset($tokens[$segment])) {
                $words = $this->wordsFor($tokens[$segment], $values);

                if ($words === null) {
                    return null;
                }

                foreach ($words as $word) {
                    $parts[] = [$word, 0];
                }
            }
        }

        $clips = AudioClip::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('language', $this->tenant->voice_language)
            ->whereIn('code', array_values(array_unique(array_column($parts, 0))))
            ->get(['id', 'code', 'segment_index'])
            ->keyBy(fn (AudioClip $clip): string => $clip->code.'#'.$clip->segment_index);

        $ids = [];

        foreach ($parts as [$partCode, $segment]) {
            $clip = $clips->get($partCode.'#'.$segment);

            if ($clip === null) {
                return null;
            }

            $ids[] = $clip->id;
        }

        return $ids;
    }

    /**
     * Mots de chiffres d'une variable, ou null si elle ne peut pas être dite.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>|null
     */
    private function wordsFor(string $token, array $values): ?array
    {
        $integer = fn (mixed $value): ?int => is_numeric($value) && (string) (int) $value === (string) $value && (int) $value >= 0
            ? (int) $value
            : null;

        switch ($token) {
            case PhraseCatalog::TOKEN_MONTANT:
                $amount = $integer($values['montant'] ?? null);

                return $amount === null ? null : NumberWords::montant($amount);
            case PhraseCatalog::TOKEN_NOMBRE:
                $number = $integer($values['nombre'] ?? null);

                return $number === null ? null : NumberWords::nombre($number);
            case PhraseCatalog::TOKEN_HEURE:
                if (! is_string($values['heure'] ?? null) || preg_match('/\A(\d{1,2}):(\d{2})\z/', $values['heure'], $m) !== 1) {
                    return null;
                }

                return (int) $m[1] > 23 || (int) $m[2] > 59 ? null : NumberWords::heure((int) $m[1], (int) $m[2]);
            default:
                // {article} et {client} : le nom dit au micro n'est pas encore enregistrable.
                return null;
        }
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
