<?php

namespace App\Domain\Storefront;

use App\Models\Storefront;
use App\Models\StorefrontOrder;
use App\Models\TenantMembership;
use App\Notifications\StorefrontOrderReceivedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Prévient le vendeur d'une nouvelle commande en ligne : e-mail à l'adresse de la
 * boutique si elle est renseignée, sinon aux administrateurs de l'entreprise.
 * Un échec d'envoi ne doit jamais faire perdre la commande du client.
 */
final class StorefrontOrderNotifier
{
    public function notify(Storefront $storefront, StorefrontOrder $order): void
    {
        try {
            $notification = new StorefrontOrderReceivedNotification(
                $storefront->tenant->display_name,
                $order->number,
                $order->customer_name,
                $order->customer_phone,
                $order->total,
                route('storefront.orders'),
            );

            if (filled($storefront->email)) {
                Notification::route('mail', $storefront->email)->notify($notification);

                return;
            }

            $admins = TenantMembership::query()->withoutGlobalScopes()
                ->where('tenant_id', $storefront->tenant_id)
                ->with(['user', 'role'])->get()
                ->filter(fn (TenantMembership $membership): bool => $membership->isActive() && $membership->isAdmin())
                ->map(fn (TenantMembership $membership) => $membership->user)
                ->filter()->unique('id');

            Notification::send($admins, $notification);
        } catch (Throwable $e) {
            Log::warning('Notification de commande en ligne non envoyée.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
