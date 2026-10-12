<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\StockLedger;
use App\Domain\Organization\Sequences;
use App\Domain\Storefront\StorefrontCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Réception de marchandises (docs/02 §6) : crée le bon de réception (immuable)
 * et l'entrée en stock au coût d'achat HT, dans la même transaction. Rejouer la
 * même réception (même clé) ne crée rien de plus.
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
        private readonly PurchaseOrderService $orders,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
        private readonly StorefrontCatalog $storefront,
    ) {}

    /**
     * Réception d'une commande confirmée.
     *
     * @param  array<string, string>  $quantities  order_line_id => quantité reçue (vide/0 = rien)
     */
    public function receiveOrder(PurchaseOrder $order, array $quantities, ?string $supplierReference, ?string $note, User $actor, string $idempotencyKey): GoodsReceipt
    {
        $this->access->assertCanWrite($this->context->tenant());

        return $this->idempotent($idempotencyKey, function () use ($order, $quantities, $supplierReference, $note, $actor, $idempotencyKey): GoodsReceipt {
            $order = $this->orders->lock($order);
            $warehouse = $this->stockAccess->warehouse($order->warehouse_id);

            if (! in_array($order->status, [PurchaseOrder::CONFIRMED, PurchaseOrder::PARTIALLY_RECEIVED], true)) {
                throw new InvalidPurchaseOperation(__('purchases.errors.order_not_receivable'));
            }

            $orderLines = $order->lines()->with('product.unit')->get()->keyBy('id');
            $lines = [];

            foreach ($quantities as $lineId => $raw) {
                if (trim((string) $raw) === '' || trim((string) $raw) === '0') {
                    continue;
                }

                /** @var PurchaseOrderLine|null $orderLine */
                $orderLine = $orderLines[$lineId] ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_line'));
                $quantity = PurchaseOrderService::quantity((string) $raw, $orderLine->product);
                $remaining = Quantity::of($orderLine->quantity)->sub(Quantity::of($orderLine->quantity_received));

                if ($quantity->compare($remaining) > 0) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.receipt_exceeds', ['product' => $orderLine->label, 'max' => $remaining->format()]));
                }

                $lines[] = ['order_line' => $orderLine, 'product' => $orderLine->product, 'quantity' => $quantity, 'unit_cost' => $orderLine->unit_cost, 'tax' => $orderLine->tax_rate_basis_points];
            }

            $receipt = $this->write($warehouse->id, $order->supplier_id, $order, $lines, $supplierReference, $note, $actor, $idempotencyKey);

            $complete = true;

            foreach ($orderLines as $orderLine) {
                $received = Quantity::of($orderLine->quantity_received);

                foreach ($lines as $line) {
                    if ($line['order_line']->id === $orderLine->id) {
                        $received = $received->add($line['quantity']);
                        $orderLine->quantity_received = $received->value;
                        $orderLine->save();
                    }
                }

                $complete = $complete && $received->compare(Quantity::of($orderLine->quantity)) >= 0;
            }

            $order->status = $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED;
            $order->version++;
            $order->save();

            return $receipt;
        });
    }

    /**
     * Réception directe, sans commande préalable.
     *
     * @param  list<array{product_id: string, quantity: string, unit_cost: int}>  $lines
     */
    public function receiveDirect(string $warehouseId, string $supplierId, array $lines, ?string $supplierReference, ?string $note, User $actor, string $idempotencyKey): GoodsReceipt
    {
        $this->access->assertCanWrite($this->context->tenant());

        return $this->idempotent($idempotencyKey, function () use ($warehouseId, $supplierId, $lines, $supplierReference, $note, $actor, $idempotencyKey): GoodsReceipt {
            $warehouse = $this->stockAccess->warehouse($warehouseId);
            $supplier = PurchaseOrderService::supplier($supplierId);
            $products = Product::query()->with(['unit', 'taxRate'])->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
            $built = [];

            foreach ($lines as $input) {
                $product = $products[$input['product_id']] ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_product'));

                if ($input['unit_cost'] < 0) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.negative_cost'));
                }

                $built[] = ['order_line' => null, 'product' => $product, 'quantity' => PurchaseOrderService::quantity($input['quantity'], $product),
                    'unit_cost' => $input['unit_cost'], 'tax' => $product->taxRate->rate_basis_points];
            }

            return $this->write($warehouse->id, $supplier->id, null, $built, $supplierReference, $note, $actor, $idempotencyKey);
        });
    }

    /**
     * @param  list<array{order_line: PurchaseOrderLine|null, product: Product, quantity: Quantity, unit_cost: int, tax: int}>  $lines
     */
    private function write(string $warehouseId, string $supplierId, ?PurchaseOrder $order, array $lines, ?string $supplierReference, ?string $note, User $actor, string $idempotencyKey): GoodsReceipt
    {
        if ($lines === []) {
            throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
        }

        $total = array_sum(array_map(fn (array $l) => $l['quantity']->times($l['unit_cost']), $lines));

        $receipt = new GoodsReceipt;
        $receipt->forceFill([
            'number' => $this->sequences->document('purchase:receipt', 'BR'),
            'warehouse_id' => $warehouseId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => $order?->id,
            'supplier_reference' => filled($supplierReference) ? trim((string) $supplierReference) : null,
            'note' => filled($note) ? trim((string) $note) : null,
            'total_excl_tax' => $total,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actor->id,
            'received_at' => now(),
        ])->save();

        $movements = [];

        foreach ($lines as $line) {
            (new GoodsReceiptLine)->forceFill([
                'receipt_id' => $receipt->id,
                'order_line_id' => $line['order_line']?->id,
                'product_id' => $line['product']->id,
                'sku' => $line['product']->sku,
                'label' => $line['product']->name,
                'unit_code' => $line['product']->unit->code,
                'quantity' => $line['quantity']->value,
                'unit_cost' => $line['unit_cost'],
                'tax_rate_basis_points' => $line['tax'],
                'line_excl_tax' => $line['quantity']->times($line['unit_cost']),
            ])->save();

            $movements[] = new MovementLine($warehouseId, $line['product']->id, $line['quantity'], $line['unit_cost']);
        }

        $this->ledger->post('receipt', "goods_receipt:{$receipt->id}", $movements, $actor, source: $receipt);

        // Tout article acheté arrive en brouillon dans la boutique en ligne (sans ressaisie).
        $this->storefront->draftProducts($this->context->tenant(), array_map(fn (array $l): string => $l['product']->id, $lines));

        $this->audit->record('purchase.goods_received', $receipt,
            after: ['number' => $receipt->number, 'order' => $order?->number, 'total_excl_tax' => $total, 'lines' => count($lines)], actor: $actor);

        return $receipt;
    }

    /**
     * @param  Closure(): GoodsReceipt  $callback
     */
    private function idempotent(string $key, Closure $callback): GoodsReceipt
    {
        $existing = GoodsReceipt::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): GoodsReceipt => $callback());
        } catch (UniqueConstraintViolationException $e) {
            return GoodsReceipt::query()->where('idempotency_key', $key)->first() ?? throw $e;
        }
    }
}
