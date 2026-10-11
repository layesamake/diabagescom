<?php

namespace App\Domain\Backup;

use App\Models\CloudBackupConnection;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client Google Drive minimal (OAuth 2.0 + API Drive v3).
 *
 * Autorisation « drive.file » : l'application ne voit et ne gère que les
 * fichiers qu'elle a créés (dossier de sauvegardes et archives), jamais le
 * reste du Drive du client. Le jeton de rafraîchissement est stocké chiffré ;
 * le jeton d'accès, de courte durée, n'est jamais conservé.
 */
final class GoogleDrive
{
    public const string SCOPES = 'openid email https://www.googleapis.com/auth/drive.file';

    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const string API = 'https://www.googleapis.com/drive/v3';

    private const string UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    public static function configured(): bool
    {
        return filled(config('services.google_drive.client_id')) && filled(config('services.google_drive.client_secret'));
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /**
     * Échange le code d'autorisation contre un jeton de rafraîchissement.
     *
     * @return array{refresh_token: string, email: string|null}
     */
    public function exchange(string $code): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'redirect_uri' => config('services.google_drive.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            throw new RuntimeException('Google token exchange failed.');
        }

        // L'autorisation « fichiers de l'application » doit avoir été accordée.
        if (! str_contains((string) $response->json('scope'), 'auth/drive.file')) {
            throw new RuntimeException('Drive permission not granted.');
        }

        return ['refresh_token' => $response->json('refresh_token'), 'email' => $this->emailFromIdToken((string) $response->json('id_token'))];
    }

    public function revoke(string $refreshToken): void
    {
        Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $refreshToken]);
    }

    /**
     * Crée le dossier de sauvegardes s'il n'existe pas encore (ou a été supprimé).
     */
    public function ensureFolder(CloudBackupConnection $connection, string $name): string
    {
        $client = $this->client($connection);

        if ($connection->folder_id !== null) {
            $folder = $client->get(self::API.'/files/'.$connection->folder_id, ['fields' => 'id,trashed']);

            if ($folder->successful() && $folder->json('trashed') !== true) {
                return $connection->folder_id;
            }
        }

        $created = $client->post(self::API.'/files?fields=id', ['name' => $name, 'mimeType' => self::FOLDER_MIME]);

        if (! $created->successful()) {
            throw new RuntimeException('Google Drive folder creation failed ('.$created->status().').');
        }

        $connection->forceFill(['folder_id' => (string) $created->json('id')])->save();

        return $connection->folder_id;
    }

    /**
     * Envoie un fichier par envoi « reprenable » (adapté aux gros fichiers).
     */
    public function upload(CloudBackupConnection $connection, string $folderId, string $path, string $name): string
    {
        $client = $this->client($connection);
        $session = $client->withHeaders(['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) filesize($path)])
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id', ['name' => $name, 'parents' => [$folderId], 'mimeType' => 'application/zip']);
        $location = $session->header('Location');

        if (! $session->successful() || $location === '') {
            throw new RuntimeException('Google Drive upload session failed ('.$session->status().').');
        }

        $stream = fopen($path, 'r') ?: throw new RuntimeException('Backup file unreadable.');

        try {
            $sent = $client->withBody(Utils::streamFor($stream), 'application/zip')->timeout(600)->put($location);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $sent->successful()) {
            throw new RuntimeException('Google Drive upload failed ('.$sent->status().').');
        }

        return (string) $sent->json('id');
    }

    /**
     * Ne garde que les sauvegardes les plus récentes dans le dossier.
     */
    public function prune(CloudBackupConnection $connection, string $folderId, int $keep): int
    {
        $client = $this->client($connection);
        $files = $client->get(self::API.'/files', [
            'q' => "'{$folderId}' in parents and trashed = false and mimeType = 'application/zip'",
            'orderBy' => 'createdTime desc',
            'fields' => 'files(id)',
            'pageSize' => 100,
        ])->json('files') ?? [];
        $deleted = 0;

        foreach (array_slice($files, $keep) as $file) {
            if ($client->delete(self::API.'/files/'.$file['id'])->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function client(CloudBackupConnection $connection): PendingRequest
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            // invalid_grant : accès retiré par le client depuis son compte Google.
            throw new RuntimeException($response->json('error') === 'invalid_grant' ? 'revoked' : 'Google token refresh failed.');
        }

        return Http::withToken($response->json('access_token'))->acceptJson()->timeout(60);
    }

    /**
     * Adresse du compte connecté, lue dans le jeton d'identité reçu directement
     * de Google (canal TLS) : sert uniquement à l'affichage.
     */
    private function emailFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : null;
    }
}
