<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Authorizer;
use App\Domain\Onboarding\StartGuide;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WarehouseAccess;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Les données de contexte sont des closures : elles sont évaluées au rendu,
     * après la résolution du contexte entreprise par le middleware de route.
     * Seuls les champs nécessaires à l'affichage quittent le serveur.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn () => [
                'user' => $request->user() instanceof User ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at?->toIso8601String(),
                ] : null,
            ],
            'context' => fn () => $this->context($request),
            'subscription' => fn () => app(TenantContext::class)->has()
                ? app(FeatureAccess::class)->summary(app(TenantContext::class)->tenant())
                : null,
            'guide' => fn () => $request->user() instanceof User && app(TenantContext::class)->has()
                ? app(StartGuide::class)->summary($request->user())
                : null,
            'platform' => fn () => [
                'can_access' => $request->user() instanceof User && Gate::forUser($request->user())->allows('platform.tenants.manage'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function context(Request $request): ?array
    {
        $user = $request->user();
        $context = app(TenantContext::class);
        $membership = $context->membership();

        if (! $user instanceof User || $membership === null) {
            return null;
        }

        $warehouse = $context->warehouse();

        return [
            'tenant' => ['id' => $membership->tenant->id, 'name' => $membership->tenant->display_name],
            'warehouse' => $warehouse ? ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name] : null,
            'role' => ['code' => $membership->role->code, 'name' => $membership->role->name],
            'tenants' => TenantMembership::query()
                ->with('tenant:id,display_name')
                ->where('user_id', $user->id)
                ->where('status', TenantMembership::STATUS_ACTIVE)
                ->get()
                ->map(fn (TenantMembership $m) => ['id' => $m->tenant->id, 'name' => $m->tenant->display_name])
                ->values(),
            'warehouses' => app(WarehouseAccess::class)->accessible($membership)
                ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name])
                ->values(),
            // Pour l'affichage du menu uniquement : chaque action est revérifiée côté serveur.
            'permissions' => app(Authorizer::class)->grantedTenantPermissions($user),
        ];
    }
}
