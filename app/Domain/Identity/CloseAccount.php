<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLog;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'un compte à la demande de son titulaire.
 *
 * Le compte n'est pas supprimé physiquement : il est référencé par le journal
 * d'audit (append-only) et, plus tard, par des documents validés. Les données
 * personnelles sont anonymisées et tous les moyens d'authentification révoqués.
 */
final class CloseAccount
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(User $user): void
    {
        $hasActiveMembership = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if ($hasActiveMembership) {
            throw ValidationException::withMessages(['password' => __('account.close_blocked_by_membership')]);
        }

        DB::transaction(function () use ($user): void {
            $this->audit->record('account.closed', $user, actor: $user);

            $user->passkeys()->delete();
            $user->forceFill([
                'name' => __('account.closed_name'),
                'email' => 'closed-'.$user->id.'@invalid.diaba.cloud',
                'password' => Str::password(64),
                'status' => 'suspended',
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
            ])->save();
        });
    }
}
