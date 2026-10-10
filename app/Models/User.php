<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Compte de la plateforme. L'appartenance aux entreprises passe par TenantMembership ;
 * un rôle plateforme (super-administrateur) ne donne aucun accès implicite aux tenants.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property string|null $platform_role_id
 * @property string $locale
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Role|null $platformRole
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const string STATUS_ACTIVE = 'active';

    /** Valeurs par défaut identiques à celles de la base, disponibles avant rechargement. */
    protected $attributes = ['status' => 'active', 'locale' => 'fr'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * E-mail toujours normalisé (une contrainte CHECK le garantit aussi en base).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'platform_role_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasPlatformRole(): bool
    {
        return $this->platform_role_id !== null;
    }

    /**
     * MFA = TOTP confirmé ou au moins une passkey enregistrée.
     */
    public function hasMultiFactorAuthentication(): bool
    {
        return $this->two_factor_confirmed_at !== null || $this->passkeys()->exists();
    }
}
