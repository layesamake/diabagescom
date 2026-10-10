<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Souscription d'une entreprise. Les changements d'état passent exclusivement
 * par App\Domain\Subscriptions\SubscriptionLifecycle (transactionnel, audité).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $status
 * @property string|null $billing_cycle
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $read_only_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    public const string TRIALING = 'trialing';

    public const string ACTIVE = 'active';

    public const string READ_ONLY = 'read_only';

    public const string SUSPENDED = 'suspended';

    public const string CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'read_only_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }
}
