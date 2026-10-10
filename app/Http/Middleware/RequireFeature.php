<?php

namespace App\Http\Middleware;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve un groupe de routes à une fonction de la formule, ex. `feature:accounting`.
 */
class RequireFeature
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $this->access->assertFeature($this->context->tenant(), $feature);

        return $next($request);
    }
}
