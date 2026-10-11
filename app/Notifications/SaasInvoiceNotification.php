<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Facture d'abonnement émise, à échéance ou en retard. Aucun détail sensible :
 * le client consulte la facture dans son espace.
 */
class SaasInvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'issued'|'due_soon'|'due'|'overdue'  $stage
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $number,
        public readonly int $total,
        public readonly string $dueOn,
        public readonly string $stage,
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
        $params = [
            'company' => $this->companyName,
            'number' => $this->number,
            'total' => number_format($this->total, 0, ',', ' '),
            'date' => $this->dueOn,
        ];

        return (new MailMessage)
            ->subject(__("billing.notices.{$this->stage}.subject", $params))
            ->greeting(__('Hello!'))
            ->line(__("billing.notices.{$this->stage}.line", $params))
            ->action(__('billing.notices.action'), url('/settings/subscription'))
            ->line(__('billing.notices.footer'));
    }
}
