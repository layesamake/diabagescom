<?php

namespace App\Http\Middleware;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\SubscriptionReadOnly;
use App\Domain\Subscriptions\SubscriptionSuspended;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique l'état de l'abonnement à tout l'espace entreprise :
 * - suspendu : seules la page d'abonnement et le changement de contexte restent accessibles ;
 * - lecture seule : toute requête de modification est refusée.
 *
 * Les services métier revérifient via FeatureAccess::assertCanWrite / assertCanAdd :
 * ce middleware n'est pas la seule barrière.
 */
class EnforceSubscription
{
    /** Routes utilisables quel que soit l'état (consulter/régulariser l'abonnement, changer d'entreprise). */
    private const array ALWAYS_ALLOWED = ['subscription.show', 'context.tenant', 'context.warehouse'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $tenant = $this->context->tenant();

        if ($this->access->isSuspended($tenant)) {
            if ($request->isMethodSafe() && ! $request->expectsJson()) {
                return redirect()->route('subscription.show');
            }

            throw new SubscriptionSuspended;
        }

        if (! $request->isMethodSafe() && ! $this->access->canWrite($tenant)) {
            throw new SubscriptionReadOnly;
        }

        return $next($request);
    }
}
