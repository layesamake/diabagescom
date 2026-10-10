<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ImageStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Règles communes aux photos d'articles et de catégories.
 */
final class CatalogImages
{
    /** Validation à l'envoi (le contenu est ensuite décodé et ré-encodé par ImageStore). */
    public const array RULES = ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'];

    /**
     * URL signée valable 30 minutes ; la version force le rafraîchissement après remplacement.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $route, ?string $path, array $parameters, int $version): ?string
    {
        return $path === null ? null : URL::temporarySignedRoute($route, now()->addMinutes(30), [...$parameters, 'v' => $version]);
    }

    public static function response(?string $path): StreamedResponse
    {
        abort_if($path === null || ! Storage::disk(ImageStore::DISK)->exists($path), 404);

        return Storage::disk(ImageStore::DISK)->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
