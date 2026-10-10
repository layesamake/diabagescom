<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Sales\Pricing;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Commandes fournisseurs : brouillon modifiable → confirmée (numéro, montants
 * figés) → réceptions partielles ou complètes. Une commande partiellement reçue
 * peut être soldée (le reliquat est abandonné) ; elle n'est jamais supprimée.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{warehouse_id: string, supplier_id: string, expected_on?: string|null, notes?: string|null, version?: int|null, lines: list<array{product_id: string, quantity: string, unit_cost: int}>}  $data
     */
    public function saveDraft(?PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($data['warehouse_id']);
        $supplier = self::supplier($data['supplier_id']);

        return DB::transaction(function () use ($order, $data, $actor, $warehouse, $supplier): PurchaseOrder {
            if ($order !== null) {
                $order = $this->lock($order);

                if ($order->status !== PurchaseOrder::DRAFT) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.not_draft'));
                }

                if (isset($data['version']) && $data['version'] !== $order->version) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.stale'));
                }

                $order->lines()->delete();
                $order->version++;
            } else {
                $order = new PurchaseOrder;
                $order->created_by = $actor->id;
            }

            $order->warehouse_id = $warehouse->id;
            $order->supplier_id = $supplier->id;
            $order->expected_on = filled($data['expected_on'] ?? null) ? CarbonImmutable::parse((string) $data['expected_on']) : null;
            $order->notes = $data['notes'] ?? null;
            $order->save();

            $totals = ['excl' => 0, 'tax' => 0];

            foreach (self::buildLines($data['lines']) as $position => $line) {
                (new PurchaseOrderLine)->forceFill([...$line, 'order_id' => $order->id, 'position' => $position + 1])->save();
                $totals['excl'] += $line['line_excl_tax'];
                $totals['tax'] += $line['line_tax'];
            }

            $order->total_excl_tax = $totals['excl'];
            $order->total_tax = $totals['tax'];
            $order->total = $totals['excl'] + $totals['tax'];
            $order->save();

            $this->audit->record('purchase.order_draft_saved', $order, after: ['supplier' => $supplier->code, 'total' => $order->total], actor: $actor);

            return $order;
        });
    }

    public function confirm(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $order = $this->lock($order);
            $this->stockAccess->warehouse($order->warehouse_id);

            if ($order->status === PurchaseOrder::CONFIRMED) {
                return $order;
            }

            if ($order->status !== PurchaseOrder::DRAFT) {
                throw new InvalidPurchaseOperation(__('purchases.errors.not_draft'));
            }

            if (! $order->lines()->exists()) {
                throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
            }

            $order->number = $this->sequences->document('purchase:order', 'CF');
            $order->status = PurchaseOrder::CONFIRMED;
            $order->validated_by = $actor->id;
            $order->validated_at = now();
            $order->version++;
            $order->save();

            $this->audit->record('purchase.order_confirmed', $order, after: ['number' => $order->number, 'total' => $order->total], actor: $actor);

            return $order;
        });
    }

    /**
     * Brouillon ou commande confirmée sans aucune réception : annulation (motif requis
     * après confirmation). Commande partiellement reçue : soldée, reliquat abandonné.
     */
    public function cancel(PurchaseOrder $order, ?string $reason, User $actor): PurchaseOrder
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = $this->lock($order);
            $this->stockAccess->warehouse($order->warehouse_id);

            if (in_array($order->status, [PurchaseOrder::CANCELLED, PurchaseOrder::CLOSED, PurchaseOrder::RECEIVED], true)) {
                throw new InvalidPurchaseOperation(__('purchases.errors.order_finished'));
            }

            if ($order->status !== PurchaseOrder::DRAFT && mb_strlen(trim((string) $reason)) < 3) {
                throw new InvalidPurchaseOperation(__('purchases.errors.reason_required'));
            }

            $order->status = $order->status === PurchaseOrder::PARTIALLY_RECEIVED ? PurchaseOrder::CLOSED : PurchaseOrder::CANCELLED;
            $order->cancelled_by = $actor->id;
            $order->cancelled_at = now();
            $order->cancel_reason = filled($reason) ? trim((string) $reason) : null;
            $order->version++;
            $order->save();

            $this->audit->record("purchase.order_{$order->status}", $order, metadata: array_filter(['reason' => $order->cancel_reason]), actor: $actor);

            return $order;
        });
    }

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
    }

    public static function supplier(string $supplierId): Supplier
    {
        return Supplier::query()->whereNull('archived_at')->find($supplierId)
            ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_supplier'));
    }

    /**
     * Lignes d'achat : instantané de l'article, coût HT, taxe de l'article.
     *
     * @param  list<array{product_id: string, quantity: string, unit_cost: int}>  $lines
     * @return list<array<string, mixed>>
     */
    public static function buildLines(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
        }

        $products = Product::query()->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $built = [];

        foreach ($lines as $input) {
            $product = $products[$input['product_id']] ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_product'));

            if ($product->type === 'service') {
                throw new InvalidPurchaseOperation(__('inventory.errors.service', ['product' => $product->name]));
            }

            $quantity = self::quantity($input['quantity'], $product);

            if ($input['unit_cost'] < 0) {
                throw new InvalidPurchaseOperation(__('purchases.errors.negative_cost'));
            }

            $amounts = Pricing::purchaseLine($quantity, $input['unit_cost'], $product->taxRate->rate_basis_points);

            $built[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'label' => $product->name,
                'unit_code' => $product->unit->code,
                'quantity' => $quantity->value,
                'unit_cost' => $input['unit_cost'],
                'tax_rate_basis_points' => $product->taxRate->rate_basis_points,
                'line_excl_tax' => $amounts['excl_tax'],
                'line_tax' => $amounts['tax'],
                'line_total' => $amounts['total'],
            ];
        }

        return $built;
    }

    public static function quantity(string $raw, Product $product): Quantity
    {
        try {
            $quantity = Quantity::of($raw);
        } catch (InvalidArgumentException $e) {
            throw new InvalidPurchaseOperation($e->getMessage());
        }

        if (! $quantity->isPositive()) {
            throw new InvalidPurchaseOperation(__('purchases.errors.positive_quantity', ['product' => $product->name]));
        }

        if (! $product->unit->decimals_allowed && ! $quantity->isWhole()) {
            throw new InvalidPurchaseOperation(__('inventory.errors.whole_quantity', ['product' => $product->name]));
        }

        return $quantity;
    }
}
