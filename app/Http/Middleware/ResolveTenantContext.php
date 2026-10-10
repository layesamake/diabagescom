<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WarehouseAccess;
use App\Models\TenantMembership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Établit le contexte entreprise/dépôt à chaque requête authentifiée.
 *
 * Les identifiants retenus en session sont revalidés à chaque requête contre les
 * appartenances actives de l'utilisateur : une appartenance révoquée ou un dépôt
 * désaffecté cesse immédiatement d'être accessible. Aucun paramètre HTTP n'est lu ici.
 *
 * Sans appartenance active, l'utilisateur est envoyé vers l'assistant de création.
 */
class ResolveTenantContext
{
    public const string SESSION_TENANT = 'context.tenant_id';

    public const string SESSION_WAREHOUSE = 'context.warehouse_id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly WarehouseAccess $warehouses,
    ) {}

    /**
     * @param  'required'|'optional'  $mode  "optional" : pages utilisables sans entreprise (profil, sécurité).
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        /** @var User $user */
        $user = $request->user();
        Context::add('user_id', $user->id);

        $membership = $this->resolveMembership($user, $request->session()->get(self::SESSION_TENANT));

        if ($membership === null) {
            $request->session()->forget([self::SESSION_TENANT, self::SESSION_WAREHOUSE]);

            if ($mode === 'optional') {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('tenancy.no_company')], Response::HTTP_FORBIDDEN)
                : redirect()->route('onboarding.create');
        }

        $warehouseId = $request->session()->get(self::SESSION_WAREHOUSE);
        $warehouse = is_string($warehouseId) ? $this->warehouses->find($membership, $warehouseId) : null;
        $warehouse ??= $this->warehouses->query($membership)->first();

        $request->session()->put(self::SESSION_TENANT, $membership->tenant_id);
        $request->session()->put(self::SESSION_WAREHOUSE, $warehouse?->id);

        $this->context->set($membership->tenant, $membership, $warehouse);

        return $next($request);
    }

    private function resolveMembership(User $user, mixed $preferredTenantId): ?TenantMembership
    {
        $memberships = TenantMembership::query()
            ->with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'))
            ->orderBy('created_at')
            ->get();

        return $memberships->firstWhere('tenant_id', $preferredTenantId) ?? $memberships->first();
    }
}
