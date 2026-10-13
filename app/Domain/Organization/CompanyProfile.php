<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLog;
use App\Domain\Catalog\ImageStore;
use App\Domain\Subscriptions\FeatureAccess;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Fiche de l'entreprise : régime de TVA, localisation Google Maps, logo,
 * cachet/signature et devanture. Les images sont ré-encodées par ImageStore
 * (WebP, sans métadonnées) et rangées par entreprise, donc sauvegardées avec elle.
 */
final class CompanyProfile
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly AuditLog $audit,
        private readonly FeatureAccess $access,
    ) {}

    public function of(Tenant $tenant): TenantProfile
    {
        return TenantProfile::query()->firstOrNew(['tenant_id' => $tenant->id]);
    }

    /**
     * Seules les clés présentes sont modifiées (le support n'envoie que le
     * régime de TVA et le lien Maps). Les champs vides deviennent « non renseigné ».
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data, User $actor, ?string $reason = null, bool $bypassSubscription = false): TenantProfile
    {
        $bypassSubscription || $this->access->assertCanWrite($tenant);
        $maps = isset($data['maps_url']) && trim($data['maps_url']) !== '' ? trim($data['maps_url']) : null;

        if ($maps !== null && ! self::isGoogleMapsUrl($maps)) {
            throw ValidationException::withMessages(['maps_url' => __('company.maps_invalid')]);
        }

        return DB::transaction(function () use ($tenant, $data, $maps, $actor, $reason): TenantProfile {
            $profile = $this->of($tenant);
            $names = ['legal_name' => $tenant->legal_name, 'display_name' => $tenant->display_name];
            $keys = ['vat_regime', 'maps_url', ...TenantProfile::DETAILS];
            $before = [...$names, ...$profile->only($keys)];

            $values = ['tenant_id' => $tenant->id, 'vat_regime' => $data['vat_regime'], 'maps_url' => $maps];

            foreach (TenantProfile::DETAILS as $field) {
                if (array_key_exists($field, $data)) {
                    $text = is_string($data[$field]) ? trim($data[$field]) : '';
                    $values[$field] = $text === '' ? null : $text;
                }
            }

            $profile->forceFill($values)->save();

            foreach (['legal_name', 'display_name'] as $field) {
                if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                    $tenant->{$field} = trim($data[$field]);
                }
            }

            $tenant->save();
            $this->audit->record('company.profile_updated', $tenant, before: $before, after: [
                'legal_name' => $tenant->legal_name, 'display_name' => $tenant->display_name, ...$profile->only($keys),
            ], metadata: $this->reasonMetadata($reason), actor: $actor, tenant: $tenant);

            return $profile;
        });
    }

    public function setImage(Tenant $tenant, string $slot, ?UploadedFile $file, User $actor, ?string $reason = null, bool $bypassSubscription = false): TenantProfile
    {
        $column = TenantProfile::IMAGES[$slot] ?? throw new \InvalidArgumentException('Unknown image slot.');
        $bypassSubscription || $this->access->assertCanWrite($tenant);

        $path = $file !== null ? $this->images->store($file, "tenants/{$tenant->id}/profile") : null;
        $profile = $this->of($tenant);
        $previous = $profile->{$column};

        try {
            DB::transaction(function () use ($profile, $tenant, $column, $path, $slot, $actor, $reason): void {
                $profile->forceFill(['tenant_id' => $tenant->id, $column => $path, 'version' => $profile->version + 1])->save();
                $this->audit->record($path === null ? 'company.image_removed' : 'company.image_changed', $tenant, metadata: ['image' => $slot, ...$this->reasonMetadata($reason)], actor: $actor, tenant: $tenant);
            });
        } catch (\Throwable $e) {
            $this->images->delete($path);

            throw $e;
        }

        $this->images->delete($previous);

        return $profile;
    }

    /**
     * @return array<string, string>
     */
    private function reasonMetadata(?string $reason): array
    {
        return $reason !== null && trim($reason) !== '' ? ['reason' => trim($reason), 'by' => 'platform'] : [];
    }

    /**
     * Coordonnées à imprimer sur les documents : lignes d'adresse et de contact,
     * mentions légales (NINEA, RCCM) et mention de bas de page.
     *
     * @return array{lines: list<string>, legal: string, footer: string|null}
     */
    public function documentInfo(Tenant $tenant): array
    {
        $p = $this->of($tenant);
        $address = trim(implode(', ', array_filter([$p->address, $p->city])));
        $contact = trim(implode(' · ', array_filter([$p->phone, $p->email])));
        $legal = trim(implode(' · ', array_filter([
            filled($p->ninea) ? 'NINEA : '.$p->ninea : null,
            filled($p->rccm) ? 'RCCM : '.$p->rccm : null,
        ])));

        return ['lines' => array_values(array_filter([$address, $contact])), 'legal' => $legal, 'footer' => filled($p->document_footer) ? $p->document_footer : null];
    }

    /**
     * Image en PNG (transparence conservée) intégrée au PDF : le moteur PDF n'a
     * besoin d'accéder ni au disque ni au réseau.
     */
    public function pngDataUri(Tenant $tenant, string $slot): ?string
    {
        $column = TenantProfile::IMAGES[$slot] ?? null;
        $path = $column !== null ? $this->of($tenant)->{$column} : null;

        if ($path === null || ! Storage::disk(ImageStore::DISK)->exists($path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk(ImageStore::DISK)->get($path));

        if ($image === false) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Lien de partage Google Maps uniquement (https). Les codes « intégrer une
     * carte » (iframe) et les autres sites sont refusés.
     */
    public static function isGoogleMapsUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        return $host === 'maps.app.goo.gl'
            || $host === 'maps.google.com'
            || ($host === 'goo.gl' && str_starts_with($path, '/maps'))
            || (preg_match('/^(www\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) === 1 && str_starts_with($path, '/maps'));
    }
}
