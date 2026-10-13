<?php

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\StockAccess;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demandes d'achat : brouillon modifiable → soumise (numéro) → approuvée ou refusée
 * (motif) → transformée en commande fournisseur par les achats. Une demande n'est
 * jamais supprimée : elle peut être annulée (motif requis une fois soumise).
 *
 * Les décisions sont tracées ; l'auto-approbation (même personne) est permise, car
 * une petite entreprise n'a parfois qu'un seul responsable, mais elle est signalée
 * dans le journal d'audit.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly Sequences $sequences,
        private readonly PurchaseOrderService $orders,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{warehouse_id: string, needed_on?: string|null, notes?: string|null, version?: int|null, lines: list<array{product_id: string, quantity: string, note?: string|null}>}  $data
     */
    public function saveDraft(?PurchaseRequest $request, array $data, User $actor): PurchaseRequest
    {
        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($data['warehouse_id']);

        return DB::transaction(function () use ($request, $data, $actor, $warehouse): PurchaseRequest {
            if ($request !== null) {
                $request = $this->lock($request);

                if ($request->status !== PurchaseRequest::DRAFT) {
                    throw new InvalidPurchaseOperation(__('purchases.requests.errors.not_draft'));
                }

                if (isset($data['version']) && $data['version'] !== $request->version) {
                    throw new InvalidPurchaseOperation(__('purchases.errors.stale'));
                }

                $request->lines()->delete();
                $request->version++;
            } else {
                $request = new PurchaseRequest;
                $request->created_by = $actor->id;
            }

            $request->warehouse_id = $warehouse->id;
            $request->needed_on = filled($data['needed_on'] ?? null) ? CarbonImmutable::parse((string) $data['needed_on']) : null;
            $request->notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;
            $request->save();

            foreach ($this->buildLines($data['lines']) as $position => $line) {
                (new PurchaseRequestLine)->forceFill([...$line, 'request_id' => $request->id, 'position' => $position + 1])->save();
            }

            $this->audit->record('purchase.request_draft_saved', $request, after: ['lines' => count($data['lines'])], actor: $actor);

            return $request;
        });
    }

    public function submit(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($request, $actor): PurchaseRequest {
            $request = $this->lock($request);
            $this->stockAccess->warehouse($request->warehouse_id);

            if ($request->status === PurchaseRequest::SUBMITTED) {
                return $request;
            }

            if ($request->status !== PurchaseRequest::DRAFT) {
                throw new InvalidPurchaseOperation(__('purchases.requests.errors.not_draft'));
            }

            if (! $request->lines()->exists()) {
                throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
            }

            $request->number = $this->sequences->document('purchase:request', 'DA');
            $request->status = PurchaseRequest::SUBMITTED;
            $request->submitted_by = $actor->id;
            $request->submitted_at = now();
            $request->version++;
            $request->save();

            $this->audit->record('purchase.request_submitted', $request, after: ['number' => $request->number], actor: $actor);

            return $request;
        });
    }

    public function approve(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        return $this->decide($request, true, null, $actor);
    }

    public function reject(PurchaseRequest $request, string $reason, User $actor): PurchaseRequest
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new InvalidPurchaseOperation(__('purchases.errors.reason_required'));
        }

        return $this->decide($request, false, trim($reason), $actor);
    }

    /**
     * Annulation : brouillon, soumise ou approuvée (pas encore commandée).
     */
    public function cancel(PurchaseRequest $request, ?string $reason, User $actor): PurchaseRequest
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($request, $reason, $actor): PurchaseRequest {
            $request = $this->lock($request);
            $this->stockAccess->warehouse($request->warehouse_id);

            if (! in_array($request->status, [PurchaseRequest::DRAFT, PurchaseRequest::SUBMITTED, PurchaseRequest::APPROVED], true)) {
                throw new InvalidPurchaseOperation(__('purchases.requests.errors.cannot_cancel'));
            }

            if ($request->status !== PurchaseRequest::DRAFT && mb_strlen(trim((string) $reason)) < 3) {
                throw new InvalidPurchaseOperation(__('purchases.errors.reason_required'));
            }

            $request->status = PurchaseRequest::CANCELLED;
            $request->cancelled_by = $actor->id;
            $request->cancelled_at = now();
            $request->cancel_reason = filled($reason) ? trim((string) $reason) : null;
            $request->version++;
            $request->save();

            $this->audit->record('purchase.request_cancelled', $request, metadata: array_filter(['reason' => $request->cancel_reason]), actor: $actor);

            return $request;
        });
    }

    /**
     * Demande approuvée → commande fournisseur en brouillon (coûts préremplis avec le
     * coût standard des articles, à ajuster par les achats). Rejouer renvoie la même commande.
     */
    public function convertToOrder(PurchaseRequest $request, string $supplierId, User $actor): PurchaseOrder
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($request, $supplierId, $actor): PurchaseOrder {
            $request = $this->lock($request);
            $this->stockAccess->warehouse($request->warehouse_id);

            if ($request->status === PurchaseRequest::ORDERED && $request->order_id !== null) {
                return PurchaseOrder::query()->findOrFail($request->order_id);
            }

            if ($request->status !== PurchaseRequest::APPROVED) {
                throw new InvalidPurchaseOperation(__('purchases.requests.errors.not_approved'));
            }

            $request->load('lines');
            $costs = Product::query()->whereIn('id', $request->lines->pluck('product_id'))->pluck('standard_cost', 'id');

            $order = $this->orders->saveDraft(null, [
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $supplierId,
                'expected_on' => $request->needed_on?->toDateString(),
                'notes' => __('purchases.requests.order_note', ['number' => $request->number]).($request->notes !== null ? "\n".$request->notes : ''),
                'lines' => array_values($request->lines->map(fn (PurchaseRequestLine $l) => [
                    'product_id' => $l->product_id,
                    'quantity' => $l->quantity,
                    'unit_cost' => (int) ($costs[$l->product_id] ?? 0),
                ])->all()),
            ], $actor);

            $request->status = PurchaseRequest::ORDERED;
            $request->order_id = $order->id;
            $request->version++;
            $request->save();

            $this->audit->record('purchase.request_ordered', $request, after: ['order' => $order->id], actor: $actor);

            return $order;
        });
    }

    private function decide(PurchaseRequest $request, bool $approved, ?string $reason, User $actor): PurchaseRequest
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($request, $approved, $reason, $actor): PurchaseRequest {
            $request = $this->lock($request);
            $this->stockAccess->warehouse($request->warehouse_id);

            if ($request->status !== PurchaseRequest::SUBMITTED) {
                throw new InvalidPurchaseOperation(__('purchases.requests.errors.not_submitted'));
            }

            $request->status = $approved ? PurchaseRequest::APPROVED : PurchaseRequest::REJECTED;
            $request->decided_by = $actor->id;
            $request->decided_at = now();
            $request->decision_reason = $reason;
            $request->version++;
            $request->save();

            $this->audit->record($approved ? 'purchase.request_approved' : 'purchase.request_rejected', $request,
                metadata: array_filter(['reason' => $reason, 'self_decision' => $request->created_by === $actor->id ? true : null]), actor: $actor);

            return $request;
        });
    }

    public function lock(PurchaseRequest $request): PurchaseRequest
    {
        /** @var PurchaseRequest */
        return PurchaseRequest::query()->lockForUpdate()->findOrFail($request->id);
    }

    /**
     * Lignes : instantané de l'article et quantité positive ; un même article n'apparaît qu'une fois.
     *
     * @param  list<array{product_id: string, quantity: string, note?: string|null}>  $lines
     * @return list<array<string, mixed>>
     */
    private function buildLines(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidPurchaseOperation(__('purchases.errors.no_lines'));
        }

        $products = Product::query()->with('unit:id,code,decimals_allowed')
            ->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $seen = [];
        $built = [];

        foreach ($lines as $input) {
            $product = $products[$input['product_id']] ?? throw new InvalidPurchaseOperation(__('purchases.errors.unknown_product'));

            if ($product->type === 'service') {
                throw new InvalidPurchaseOperation(__('inventory.errors.service', ['product' => $product->name]));
            }

            if (isset($seen[$product->id])) {
                throw new InvalidPurchaseOperation(__('purchases.requests.errors.duplicate', ['product' => $product->name]));
            }

            $seen[$product->id] = true;
            $quantity = PurchaseOrderService::quantity($input['quantity'], $product);

            $built[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'label' => $product->name,
                'unit_code' => $product->unit->code,
                'quantity' => $quantity->value,
                'note' => filled($input['note'] ?? null) ? mb_substr(trim((string) $input['note']), 0, 200) : null,
            ];
        }

        return $built;
    }
}
