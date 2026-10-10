<?php

namespace App\Providers;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\RecordAuthenticationEvents;
use App\Domain\Identity\Authorizer;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Support\Health\CheckCriticalDependencies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Portée « requête/job » : réinitialisés entre deux requêtes ou deux jobs,
        // pour qu'aucun contexte d'entreprise ne fuie de l'un à l'autre.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Authorizer::class);
        $this->app->scoped(AuditLog::class);
        $this->app->scoped(FeatureAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();

        Event::listen(DiagnosingHealth::class, CheckCriticalDependencies::class);
        Event::subscribe(RecordAuthenticationEvents::class);
    }

    /**
     * Toute capacité du catalogue (ex. "sale.validate") est évaluée par l'Authorizer ;
     * les autres capacités restent gérées par les policies Laravel habituelles.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! PermissionCatalog::has($ability)) {
                return null;
            }

            return app(Authorizer::class)->allows($user, $ability);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
