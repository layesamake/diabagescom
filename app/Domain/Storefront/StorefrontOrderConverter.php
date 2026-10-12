<?php

namespace App\Domain\Storefront;

use App\Domain\Audit\AuditLog;
use App\Domain\Catalog\PartyService;
use App\Domain\Sales\InvalidSalesOperation;
use App\Domain\Sales\SalesDocumentService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\StorefrontOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Transforme une commande web confirmée en commande client (brouillon) du cœur
 * de gestion : client retrouvé par son téléphone ou créé, lignes au prix de la
 * boutique, lien conservé. Rejouer la conversion ne crée rien de plus.
 */
final class StorefrontOrderConverter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SalesDocumentService $sales,
        private readonly PartyService $parties,
        private readonly AuditLog $audit,
    ) {}

    public function convert(StorefrontOrder $order, User $actor): SalesDocument
    {
        return DB::transaction(function () use ($order, $actor): SalesDocument {
            $order = StorefrontOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            if ($order->sales_document_id !== null) {
                return SalesDocument::query()->findOrFail($order->sales_document_id);
            }

            $warehouse = $this->context->warehouse() ?? Warehouse::query()->orderBy('created_at')->first()
                ?? throw new InvalidSalesOperation(__('inventory.errors.unknown_warehouse'));
            $customer = $this->customerFor($order);
            $lines = $order->items->filter(fn ($item) => $item->product_id !== null)->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                'unit_price' => $item->unit_price,
            ])->values()->all();

            $note = "Commande web {$order->number} · ".($order->fulfillment === 'delivery' ? 'livraison : '.$order->delivery_address : 'retrait sur place');
            $document = $this->sales->saveDraft(null, SalesDocument::ORDER, [
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer->id,
                'notes' => filled($order->notes) ? "{$note}\n{$order->notes}" : $note,
                'lines' => $lines,
            ], $actor);

            $order->forceFill(['customer_id' => $customer->id, 'sales_document_id' => $document->id])->save();
            $this->audit->record('storefront.order_converted', $order, after: ['sales_document_id' => $document->id, 'customer_id' => $customer->id], actor: $actor);

            return $document;
        });
    }

    private function customerFor(StorefrontOrder $order): Customer
    {
        $existing = Customer::query()->whereNull('archived_at')->where('phone', $order->customer_phone)->orderBy('created_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var Customer $customer */
        $customer = $this->parties->create(Customer::class, [
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'email' => $order->customer_email,
            'address_line' => $order->delivery_address,
        ]);

        return $customer;
    }
}
