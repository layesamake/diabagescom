<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Audio\AudioLibrary;
use App\Domain\Audio\PhraseCatalog;
use App\Domain\Onboarding\StartGuide;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AudioClip;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mode simple — lot 4 : Messages audio (/simple/voix).
 *
 * Réservé à l'administrateur (cahier des charges, section « Rôles et
 * accès ») : c'est lui qui choisit la langue de la voix et enregistre les
 * phrases à l'étape 2 des Premiers pas.
 */
class SimpleVoiceController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(TenantContext $context): Response
    {
        $tenant = $this->adminTenant($context);
        $library = new AudioLibrary($tenant);
        $codes = array_keys(PhraseCatalog::all());
        $availability = $library->availability($codes);
        $recorded = $tenant->voice_language === null ? collect() : AudioClip::query()
            ->where('tenant_id', $tenant->id)
            ->where('language', $tenant->voice_language)
            ->whereIn('code', $codes)
            ->get(['id', 'code', 'segment_index'])
            ->groupBy('code');

        $groups = [];

        foreach (PhraseCatalog::GROUPS as $group) {
            $groupCodes = PhraseCatalog::codesInGroup($group);
            $groups[] = [
                'name' => $group,
                'core' => in_array($group, PhraseCatalog::CORE_GROUPS, true),
                'phrases' => array_map(function (string $code) use ($availability, $recorded) {
                    $clips = $recorded->get($code, collect())->keyBy('segment_index');

                    return [
                        'code' => $code,
                        'text' => PhraseCatalog::text($code),
                        'tokens' => PhraseCatalog::tokens($code),
                        'segments' => array_map(fn (int $segment) => [
                            'index' => $segment,
                            'clip_id' => $clips->get($segment)?->id,
                        ], PhraseCatalog::requiredSegments($code)),
                        'disponible' => $availability[$code] ?? false,
                    ];
                }, $groupCodes),
            ];
        }

        return Inertia::render('simple/Voix', [
            'language' => $tenant->voice_language,
            'groups' => $groups,
            'progress' => $library->coreProgress(),
        ]);
    }

    public function updateLanguage(Request $request, TenantContext $context): RedirectResponse
    {
        $tenant = $this->adminTenant($context);

        $data = $request->validate([
            // Codes ISO 639 (wo, ff, bm, srr, dyo, snk, mnk…) ou « autre » —
            // le nom libre associé à « autre » n'est pas encore saisi dans
            // cette première version de l'écran (évolution possible).
            'language' => ['required', 'string', 'max:16', 'regex:/\A([a-z]{2,3}(-[a-z]+)?|autre)\z/i'],
        ]);

        $tenant->voice_language = $data['language'];
        $tenant->save();

        return to_route('simple.voice.index');
    }

    public function storeSegment(Request $request, TenantContext $context, string $code, int $segment): RedirectResponse
    {
        $tenant = $this->adminTenant($context);
        abort_if($tenant->voice_language === null, 422, "Choisissez la langue avant d'enregistrer.");

        $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/m4a,audio/x-m4a', 'max:512'],
        ]);

        abort_unless(PhraseCatalog::exists($code), 404);

        $library = new AudioLibrary($tenant);
        $library->record($code, $segment, $request->file('audio'), $request->user());

        $this->syncGuideStep($context, $library);

        return to_route('simple.voice.index');
    }

    public function destroySegment(TenantContext $context, string $code, int $segment): RedirectResponse
    {
        $tenant = $this->adminTenant($context);
        abort_unless(PhraseCatalog::exists($code), 404);

        (new AudioLibrary($tenant))->delete($code, $segment);

        return to_route('simple.voice.index');
    }

    /** Lecture d'un morceau enregistré ; la liaison de route filtre déjà par entreprise. */
    public function clip(TenantContext $context, AudioClip $clip): StreamedResponse
    {
        $this->adminTenant($context);
        abort_unless(Storage::disk('local')->exists($clip->file_path), 404);

        return Storage::disk('local')->response($clip->file_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function adminTenant(TenantContext $context): Tenant
    {
        abort_unless($context->membership()?->isAdmin(), 403);

        return $context->tenant();
    }

    /** Coche l'étape 2 du guide dès que les 71 enregistrements « cœur » existent. */
    private function syncGuideStep(TenantContext $context, AudioLibrary $library): void
    {
        if (! $library->coreProgress()['complete']) {
            return;
        }

        $user = $context->membership()?->user;

        if ($user !== null) {
            app(StartGuide::class)->mark($user, 'voice', StartGuide::DONE);
        }
    }
}
