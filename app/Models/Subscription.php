<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * Filtre SQL sur le statut effectif, mêmes règles que SubscriptionClock::effective()
     * (un essai ou une période échus passent en lecture seule, puis en suspension).
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeEffectiveStatus(Builder $query, string $status, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $graceStart = $now->subDays((int) config('diaba.subscriptions.read_only_days'));

        $lapsed = fn (Builder $q, string $stored, string $column, bool $beyondGrace) => $q
            ->where('status', $stored)
            ->where($column, '<=', $now)
            ->where($column, $beyondGrace ? '<=' : '>', $graceStart);

        match ($status) {
            self::TRIALING => $query->where('status', self::TRIALING)
                ->where(fn (Builder $q) => $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>', $now)),
            self::ACTIVE => $query->where('status', self::ACTIVE)
                ->where(fn (Builder $q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>', $now)),
            self::READ_ONLY => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', self::READ_ONLY)
                    ->where(fn (Builder $q) => $q->whereNull('read_only_ends_at')->orWhere('read_only_ends_at', '>', $now)))
                ->orWhere(fn (Builder $q) => $lapsed($q, self::TRIALING, 'trial_ends_at', false))
                ->orWhere(fn (Builder $q) => $lapsed($q, self::ACTIVE, 'current_period_end', false))),
            self::SUSPENDED => $query->where(fn (Builder $q) => $q
                ->where('status', self::SUSPENDED)
                ->orWhere(fn (Builder $q) => $q->where('status', self::READ_ONLY)->where('read_only_ends_at', '<=', $now))
                ->orWhere(fn (Builder $q) => $lapsed($q, self::TRIALING, 'trial_ends_at', true))
                ->orWhere(fn (Builder $q) => $lapsed($q, self::ACTIVE, 'current_period_end', true))),
            default => $query->where('status', $status),
        };
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
