<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Audio\AudioLibrary;
use App\Domain\Audio\PhraseCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AudioClip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mode simple — lecture des messages audio par tous les membres.
 *
 * L'enregistrement reste réservé à l'administrateur (SimpleVoiceController) ;
 * ici, n'importe quel membre de l'entreprise peut écouter les phrases déjà
 * enregistrées, pour que le haut-parleur serve vraiment au vendeur.
 */
class SimpleAudioController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    /** Morceaux à jouer, dans l'ordre, pour une phrase ; 404 si elle n'est pas entièrement enregistrée. */
    public function phrase(Request $request, TenantContext $context, string $code): JsonResponse
    {
        abort_unless(PhraseCatalog::exists($code), 404);

        $values = $request->validate([
            'montant' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'nombre' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'heure' => ['nullable', 'regex:/\A\d{1,2}:\d{2}\z/'],
        ]);

        $ids = (new AudioLibrary($context->tenant()))->playlist($code, $values);
        abort_if($ids === null, 404);

        return response()->json([
            'clips' => array_map(
                fn (string $id): string => route('simple.audio.clips.show', ['clip' => $id], false),
                $ids,
            ),
        ]);
    }

    /** Un morceau enregistré ; la liaison de route filtre déjà par entreprise. */
    public function clip(AudioClip $clip): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($clip->file_path), 404);

        return Storage::disk('local')->response($clip->file_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
