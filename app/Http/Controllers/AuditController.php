<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consultation du journal d'audit, limitée à l'entreprise active.
 */
class AuditController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('audit.view');

        $events = AuditEvent::query()
            ->with('actor:id,name')
            ->where('tenant_id', $context->tenantId())
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'entity_type' => $event->entity_type,
                'actor' => $event->actor?->name,
                'created_at' => $event->created_at->toIso8601String(),
                'ip' => $event->ip,
            ]);

        return Inertia::render('settings/Audit', ['events' => $events]);
    }
}
