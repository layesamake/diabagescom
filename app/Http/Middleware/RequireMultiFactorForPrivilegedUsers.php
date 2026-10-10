<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA obligatoire pour les super-administrateurs et les administrateurs d'entreprise
 * (docs/09). Tant qu'aucun TOTP confirmé ni passkey n'existe, seules les pages de
 * sécurité restent accessibles.
 */
class RequireMultiFactorForPrivilegedUsers
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $privileged = $user->hasPlatformRole() || ($this->context->membership()?->isAdmin() ?? false);

        if (! $privileged || $user->hasMultiFactorAuthentication()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('security.mfa_required')], Response::HTTP_FORBIDDEN);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('security.mfa_required')]);

        return redirect()->route('security.edit');
    }
}
