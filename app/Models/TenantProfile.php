<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiche de l'entreprise cliente (une par entreprise).
 *
 * @property string $tenant_id
 * @property string $vat_regime
 * @property string|null $maps_url
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $city
 * @property string|null $ninea
 * @property string|null $rccm
 * @property string|null $document_footer
 * @property string|null $logo_path
 * @property string|null $stamp_path
 * @property string|null $cover_path
 * @property int $version
 * @property CarbonImmutable|null $updated_at
 */
class TenantProfile extends Model
{
    public const array VAT_REGIMES = ['real', 'cgu', 'exempt'];

    /** Coordonnées et mentions légales modifiables par l'entreprise. */
    public const array DETAILS = ['phone', 'email', 'address', 'city', 'ninea', 'rccm', 'document_footer'];

    /** Images gérables : emplacement => colonne. */
    public const array IMAGES = ['logo' => 'logo_path', 'stamp' => 'stamp_path', 'cover' => 'cover_path'];

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['vat_regime' => 'real', 'version' => 0];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
