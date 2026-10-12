<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation d'un collaborateur : lien pour choisir son mot de passe (nouveau
 * compte, valable 60 minutes) ou pour se connecter (compte existant).
 */
class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $invitedBy,
        public readonly string $roleName,
        public readonly string $link,
        public readonly bool $newAccount,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('organization.team.mail.subject', ['company' => $this->companyName]))
            ->greeting(__('organization.team.mail.greeting'))
            ->line(__('organization.team.mail.intro', ['name' => $this->invitedBy, 'company' => $this->companyName, 'role' => $this->roleName]));

        return $this->newAccount
            ? $mail->line(__('organization.team.mail.new_account'))
                ->action(__('organization.team.mail.set_password'), $this->link)
                ->line(__('organization.team.mail.expires', ['minutes' => (string) config('auth.passwords.users.expire', 60)]))
            : $mail->line(__('organization.team.mail.existing_account'))
                ->action(__('organization.team.mail.login'), $this->link);
    }
}
