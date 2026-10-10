<?php

use App\Domain\BusinessRuleViolation;
use App\Domain\Subscriptions\SubscriptionRestriction;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceSubscription;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\RequireMultiFactorForPrivilegedUsers;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'mfa.privileged' => RequireMultiFactorForPrivilegedUsers::class,
            'subscription' => EnforceSubscription::class,
            'feature' => RequireFeature::class,
        ]);

        // Le contexte d'entreprise doit exister AVANT la résolution des paramètres
        // de route ({product}…) : le scope tenant filtre alors la recherche, et un
        // identifiant d'une autre entreprise aboutit à un 404.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenantContext::class);

        // Le TLS est terminé par Caddy, puis Nginx, sur le réseau Docker privé.
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Refus liés à l'abonnement : message clair, jamais une page d'erreur technique.
        $exceptions->render(function (SubscriptionRestriction $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return $request->isMethodSafe()
                ? redirect()->route('dashboard')
                : back(303);
        });
        $exceptions->dontReport(SubscriptionRestriction::class);

        // Règles métier refusées (stock insuffisant, document déjà validé…) : message clair.
        $exceptions->render(function (BusinessRuleViolation $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back(303)->withErrors(['business' => $e->getMessage()]);
        });
        $exceptions->dontReport(BusinessRuleViolation::class);
    })->create();
