<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Journalise connexions, échecs, déconnexions et réinitialisations de mot de passe
 * (audit plateforme, sans tenant). L'e-mail tenté lors d'un échec n'est conservé
 * que sous forme d'empreinte, pour corréler sans stocker la saisie brute.
 */
final class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login', $event->user, metadata: ['remember' => $event->remember], actor: $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $email = mb_strtolower(trim((string) ($event->credentials['email'] ?? '')));

        $this->audit->record('auth.failed', metadata: [
            'email_hash' => hash_hmac('sha256', $email, (string) config('app.key')),
            'known_user' => $event->user instanceof User,
        ], actor: $event->user instanceof User ? $event->user : null);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, actor: $event->user);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.password_reset', $event->user, actor: $event->user);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
