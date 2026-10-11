<?php

namespace App\Domain\Backup;

/**
 * Signature HMAC du manifeste d'une sauvegarde (clé de l'application). Le
 * manifeste contient l'empreinte de chaque fichier : une archive modifiée,
 * fabriquée ou issue d'un autre serveur est refusée à la restauration.
 * Les clés précédentes (APP_PREVIOUS_KEYS) restent acceptées après rotation.
 */
final class ArchiveSignature
{
    private const string CONTEXT = 'diaba-tenant-export|';

    public static function sign(string $manifest): string
    {
        return hash_hmac('sha256', $manifest, self::CONTEXT.self::key((string) config('app.key')));
    }

    public static function verify(string $manifest, string $signature): bool
    {
        $keys = [(string) config('app.key'), ...array_map('strval', (array) config('app.previous_keys', []))];

        foreach (array_filter($keys) as $key) {
            if (hash_equals(hash_hmac('sha256', $manifest, self::CONTEXT.self::key($key)), trim($signature))) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
