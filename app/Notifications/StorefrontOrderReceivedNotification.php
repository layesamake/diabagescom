<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvelle commande reçue par la boutique en ligne. Aucune donnée de paiement :
 * seulement de quoi identifier la commande et le client à rappeler.
 */
class StorefrontOrderReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $orderNumber,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly int $total,
        public readonly string $link,
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
        return (new MailMessage)
            ->subject("Nouvelle commande en ligne {$this->orderNumber} — {$this->companyName}")
            ->greeting('Nouvelle commande !')
            ->line("{$this->customerName} ({$this->customerPhone}) vient de passer la commande {$this->orderNumber} sur votre boutique en ligne.")
            ->line('Total indicatif : '.number_format($this->total, 0, ',', ' ').' XOF')
            ->action('Traiter la commande', $this->link)
            ->line('Rappelez le client pour confirmer les modalités de retrait ou de livraison.');
    }
}
