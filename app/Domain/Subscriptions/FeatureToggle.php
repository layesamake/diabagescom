<?php

namespace App\Domain\Subscriptions;

use App\Domain\Audit\AuditLog;
use App\Models\JournalEntry;
use App\Models\StorefrontOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Activation ou désactivation d'une fonction (comptabilité, boutique en
 * ligne) par l'administrateur de l'entreprise, indépendamment de la formule :
 * celle-ci autorise la fonction, l'administrateur choisit de s'en servir.
 * Désactivée par défaut (l'administrateur l'active volontairement).
 *
 * Une désactivation ne doit jamais rendre des données déjà saisies
 * inaccessibles sans prévenir : on la refuse plutôt que de les masquer.
 */
final class FeatureToggle
{
    /** @var array<string, string> fonction => colonne tenants */
    private const array COLUMNS = [
        FeatureAccess::FEATURE_ACCOUNTING => 'accounting_activated',
        FeatureAccess::FEATURE_ONLINE_STORE => 'online_store_activated',
    ];

    public function __construct(
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
    ) {}

    public function setEnabled(Tenant $tenant, string $feature, bool $enabled, User $actor): void
    {
        $column = self::COLUMNS[$feature] ?? throw new InvalidArgumentException("Unknown feature [{$feature}].");

        $this->access->assertCanWrite($tenant);

        if ($enabled && ! $this->access->planIncludesFeature($tenant, $feature)) {
            throw ValidationException::withMessages([
                'feature' => __('subscription.errors.feature_not_included', ['feature' => __("subscription.features.{$feature}")]),
            ]);
        }

        if (! $enabled && $this->hasExistingData($tenant, $feature)) {
            throw ValidationException::withMessages([
                'feature' => __("subscription.errors.feature_has_data.{$feature}"),
            ]);
        }

        if ($tenant->{$column} === $enabled) {
            return;
        }

        DB::transaction(function () use ($tenant, $column, $enabled): void {
            $before = [$column => $tenant->{$column}];
            $tenant->{$column} = $enabled;
            $tenant->save();
            $this->audit->record(
                $enabled ? 'tenant.feature_activated' : 'tenant.feature_deactivated',
                $tenant,
                before: $before,
                after: [$column => $enabled],
                tenant: $tenant,
            );
        });
    }

    private function hasExistingData(Tenant $tenant, string $feature): bool
    {
        return match ($feature) {
            FeatureAccess::FEATURE_ACCOUNTING => JournalEntry::query()->where('tenant_id', $tenant->id)->exists(),
            FeatureAccess::FEATURE_ONLINE_STORE => StorefrontOrder::query()->where('tenant_id', $tenant->id)->exists(),
            default => false,
        };
    }
}
