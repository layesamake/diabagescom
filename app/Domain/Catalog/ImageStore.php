<?php

namespace App\Domain\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrement sécurisé des photos (articles, catégories).
 *
 * L'image reçue n'est jamais conservée telle quelle : elle est décodée puis
 * ré-encodée en WebP par GD. Cela supprime les métadonnées (position GPS des
 * photos de téléphone, EXIF), neutralise un fichier piégé déguisé en image et
 * limite le poids (1200 px maximum sur le plus grand côté).
 *
 * Stockage privé hors racine web ; affichage uniquement par URL signée.
 */
final class ImageStore
{
    public const string DISK = 'local';

    private const int MAX_SIDE = 1200;

    private const int QUALITY = 82;

    /**
     * @param  string  $folder  ex. « tenants/{id}/products »
     * @return string chemin relatif au disque privé
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $data = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($data);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => __('catalog.product.image_type')]);
        }

        $source = @imagecreatefromstring($data);

        if ($source === false) {
            throw ValidationException::withMessages(['image' => __('catalog.product.image_type')]);
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->applyExifOrientation($source, $data);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, self::MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagewebp($target, null, self::QUALITY);
        $encoded = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        $path = trim($folder, '/').'/'.Str::uuid7().'.webp';
        Storage::disk(self::DISK)->put($path, $encoded);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Duplique un fichier déjà stocké sous un nouveau nom, dans le même
     * dossier (ou un autre). Utilisé pour cloner un article avec sa photo.
     */
    public function copy(string $path, string $folder): ?string
    {
        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'webp';
        $target = trim($folder, '/').'/'.Str::uuid7().'.'.$extension;

        Storage::disk(self::DISK)->copy($path, $target);

        return $target;
    }

    /**
     * Les téléphones enregistrent souvent la photo « couchée » avec une étiquette
     * d'orientation : on la redresse avant de supprimer les métadonnées.
     */
    private function applyExifOrientation(\GdImage $image, string $data): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
