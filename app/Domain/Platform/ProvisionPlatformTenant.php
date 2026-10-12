<?php

namespace App\Domain\Platform;

use App\Domain\Tenancy\CreateTenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Provisionne une entreprise cliente depuis la console propriétaire.
 *
 * Le compte client reçoit toujours une invitation : aucun mot de passe n'est
 * transmis ou généré par le propriétaire. Le super-administrateur reste hors
 * du tenant et n'obtient jamais accès aux données métier.
 */
final class ProvisionPlatformTenant
{
    public function __construct(private readonly CreateTenant $createTenant) {}

    /**
     * @param  array{owner_name: string, owner_email: string, legal_name: string, display_name: string, country_code: string, timezone: string, warehouse_name: string, warehouse_code: string, warehouse_address?: string|null, plan_code: string}  $data
     */
    public function __invoke(User $actor, array $data): TenantMembership
    {
        $email = mb_strtolower(trim($data['owner_email']));

        return DB::transaction(function () use ($actor, $data, $email): TenantMembership {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            $newAccount = $user === null;

            if ($user === null) {
                $user = User::query()->forceCreate([
                    'name' => trim($data['owner_name']),
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                ]);
            }

            if ($user->platform_role_id !== null) {
                throw ValidationException::withMessages([
                    'owner_email' => __('platform.create.errors.platform_account'),
                ]);
            }

            $membership = ($this->createTenant)($user, $data, $actor);
            $link = $newAccount
                ? route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email])
                : route('login');

            $user->notify(new TeamInvitationNotification(
                $membership->tenant->display_name,
                $actor->name,
                __('platform.create.invited_role'),
                $link,
                $newAccount,
            ));

            return $membership;
        });
    }
}
