<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Résumé quotidien des points à traiter. Uniquement des compteurs : aucun nom
 * d'article ou de client, aucun montant (le message peut s'afficher sur un
 * écran verrouillé).
 */
class AlertDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, int>  $counts  nombre de points à traiter par catégorie
     */
    public function __construct(
        public readonly string $companyName,
        public readonly array $counts,
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
            ->subject(__('notifications.digest.subject', ['company' => $this->companyName]))
            ->greeting(__('Hello!'))
            ->line(__('notifications.digest.intro', ['company' => $this->companyName]));

        foreach ($this->counts as $category => $count) {
            $mail->line('- '.__("notifications.digest.lines.{$category}", ['count' => $count]));
        }

        return $mail
            ->action(__('notifications.digest.action'), url('/dashboard'))
            ->line(__('notifications.digest.footer'));
    }
}
