<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel de fin d'essai (J-3, J-1, J0). Ne contient aucune donnée sensible.
 */
class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly int $daysLeft,
        public readonly CarbonImmutable $endsAt,
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
        $key = $this->daysLeft === 0 ? 'ended' : 'ending';

        return (new MailMessage)
            ->subject(__("subscription.notices.{$key}.subject", ['company' => $this->companyName, 'days' => $this->daysLeft]))
            ->greeting(__('Hello!'))
            ->line(__("subscription.notices.{$key}.line", [
                'company' => $this->companyName,
                'days' => $this->daysLeft,
                'date' => $this->endsAt->translatedFormat('j F Y'),
                'read_only_days' => config('diaba.subscriptions.read_only_days'),
            ]))
            ->action(__('subscription.notices.action'), url('/settings/subscription'))
            ->line(__('subscription.notices.footer'));
    }
}
