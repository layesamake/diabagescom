<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;

/**
 * Point d'écriture unique du journal d'audit (append-only).
 *
 * Doit être appelé dans la même transaction que la mutation auditée pour que
 * l'action et sa trace soient validées ou annulées ensemble.
 */
final class AuditLog
{
    /** Champs jamais recopiés dans before/after. */
    private const array REDACTED = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'secret',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?User $actor = null,
        ?Tenant $tenant = null,
    ): AuditEvent {
        $actor ??= $this->request->user();
        $tenant ??= $this->context->tenantOrNull();

        return AuditEvent::query()->create([
            'tenant_id' => $tenant?->id,
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'entity_type' => $entity !== null ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey() !== null ? (string) $entity->getKey() : null,
            'before' => $before !== null ? $this->sanitize($before) : null,
            'after' => $after !== null ? $this->sanitize($after) : null,
            'metadata' => $metadata !== [] ? $this->sanitize($metadata) : null,
            'request_id' => Context::get('request_id'),
            'ip' => $this->request->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        return Arr::except($values, self::REDACTED);
    }
}
