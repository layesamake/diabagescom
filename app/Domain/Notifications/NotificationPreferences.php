<?php

namespace App\Domain\Notifications;

use App\Domain\Audit\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Canaux choisis par chaque utilisateur, par entreprise et par catégorie.
 *
 * Canaux validés : dans l'application (par défaut actif) et e-mail (par défaut
 * désactivé : un résumé n'est envoyé que sur demande). Push et WhatsApp ne sont
 * pas activés tant qu'ils ne sont pas validés (docs/02 §11).
 */
final class NotificationPreferences
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>|null  $categories  limiter à ces catégories
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function for(User $user, ?array $categories = null): array
    {
        $stored = DB::table('notification_preferences')
            ->where('tenant_id', $this->context->tenantId())->where('user_id', $user->id)
            ->get()->keyBy('category');

        $result = [];

        foreach ($categories ?? AlertCenter::CATEGORIES as $category) {
            $row = $stored->get($category);
            $result[$category] = ['in_app' => $row === null ? true : (bool) $row->in_app, 'email' => $row !== null && (bool) $row->email];
        }

        return $result;
    }

    /**
     * Enregistre les choix pour les catégories autorisées ; les autres sont ignorées.
     *
     * @param  array<string, array{in_app?: bool, email?: bool}>  $choices
     * @param  list<string>  $allowed
     */
    public function save(User $user, array $choices, array $allowed): void
    {
        $current = $this->for($user, $allowed);
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($user, $choices, $allowed, $current, $tenantId): void {
            foreach ($allowed as $category) {
                $next = [
                    'in_app' => (bool) ($choices[$category]['in_app'] ?? false),
                    'email' => (bool) ($choices[$category]['email'] ?? false),
                ];

                DB::table('notification_preferences')->upsert(
                    [['tenant_id' => $tenantId, 'user_id' => $user->id, 'category' => $category, ...$next, 'created_at' => now(), 'updated_at' => now()]],
                    ['tenant_id', 'user_id', 'category'],
                    ['in_app', 'email', 'updated_at'],
                );
            }

            $this->audit->record('notifications.preferences_updated', $user, before: $current, after: $this->for($user, $allowed), actor: $user);
        });
    }
}
