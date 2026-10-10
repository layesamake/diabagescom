<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\InventoryMovement;
use App\Models\StockOperation;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Transfert entre dépôts en deux temps (docs/02) :
 * - l'expédition sort la marchandise du dépôt source (statut « en transit ») ;
 * - la réception l'ajoute au dépôt de destination, au coût de sortie ;
 * - un écart à la réception doit être justifié ; la quantité manquante n'entre jamais.
 */
final class TransferService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly StockAccess $stockAccess,
        private readonly StockLedger $ledger,
        private readonly Sequences $sequences,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string}>  $lines
     */
    public function create(string $sourceId, string $destinationId, array $lines, ?string $notes, User $actor): StockTransfer
    {
        $this->access->assertCanWrite($this->context->tenant());
        $source = $this->stockAccess->warehouse($sourceId);
        $destination = Warehouse::query()->where('status', Warehouse::STATUS_ACTIVE)->find($destinationId)
            ?? throw new InvalidStockOperation(__('inventory.errors.unknown_warehouse'));

        if ($source->id === $destination->id) {
            throw new InvalidStockOperation(__('inventory.errors.same_warehouse'));
        }

        return DB::transaction(function () use ($source, $destination, $lines, $notes, $actor): StockTransfer {
            $transfer = new StockTransfer;
            $transfer->source_warehouse_id = $source->id;
            $transfer->destination_warehouse_id = $destination->id;
            $transfer->notes = $notes;
            $transfer->created_by = $actor->id;
            $transfer->save();

            foreach ($this->normalize($lines) as $productId => $quantity) {
                $transfer->lines()->create(['product_id' => $productId, 'quantity' => $quantity->value]);
            }

            $this->audit->record('stock.transfer_created', $transfer, after: ['source' => $source->code, 'destination' => $destination->code]);

            return $transfer;
        });
    }

    public function ship(StockTransfer $transfer, User $actor, string $idempotencyKey): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $idempotencyKey): StockTransfer {
            $locked = $this->lock($transfer);

            // Rejeu de la même expédition : rien à refaire.
            if ($locked->status === StockTransfer::IN_TRANSIT && $this->ledgerKnows($idempotencyKey)) {
                return $locked;
            }

            $this->expectStatus($locked, StockTransfer::DRAFT);
            $this->stockAccess->warehouse($locked->source_warehouse_id);

            $lines = $locked->lines->map(fn (StockTransferLine $line) => new MovementLine(
                $locked->source_warehouse_id, $line->product_id, Quantity::of($line->quantity)->negate(),
            ))->values()->all();
            /** @var list<MovementLine> $lines */
            $this->ledger->post('transfer_shipment', $idempotencyKey, $lines, $actor, source: $locked);

            $locked->number = $this->sequences->formatted('stock_transfer:'.now()->year, 'TR-'.now()->year);
            $locked->status = StockTransfer::IN_TRANSIT;
            $locked->shipped_by = $actor->id;
            $locked->shipped_at = now();
            $locked->version++;
            $locked->save();

            $this->audit->record('stock.transfer_shipped', $locked, after: ['number' => $locked->number]);

            return $locked;
        });
    }

    /**
     * @param  array<string, string>  $received  product_id => quantité reçue (absente = quantité expédiée)
     */
    public function receive(StockTransfer $transfer, array $received, ?string $note, User $actor, string $idempotencyKey): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $received, $note, $actor, $idempotencyKey): StockTransfer {
            $locked = $this->lock($transfer);

            if ($locked->status === StockTransfer::RECEIVED && $this->ledgerKnows($idempotencyKey)) {
                return $locked;
            }

            $this->expectStatus($locked, StockTransfer::IN_TRANSIT);
            $this->stockAccess->warehouse($locked->destination_warehouse_id);

            // Coût de sortie du dépôt source, par article : la valeur suit la marchandise.
            $costs = InventoryMovement::query()
                ->where('type', 'transfer_out')
                ->whereHas('operation', fn ($q) => $q->where('source_type', 'StockTransfer')->where('source_id', $locked->id))
                ->pluck('unit_cost', 'product_id');

            $movements = [];
            $discrepancy = false;

            foreach ($locked->lines as $line) {
                $shipped = Quantity::of($line->quantity);
                $quantity = array_key_exists($line->product_id, $received) ? Quantity::of($received[$line->product_id]) : $shipped;

                if ($quantity->isNegative() || $quantity->compare($shipped) > 0) {
                    throw new InvalidStockOperation(__('inventory.errors.received_exceeds', ['product' => $line->product->name]));
                }

                $discrepancy = $discrepancy || $quantity->compare($shipped) !== 0;
                $line->quantity_received = $quantity->value;
                $line->save();

                if ($quantity->isPositive()) {
                    $movements[] = new MovementLine($locked->destination_warehouse_id, $line->product_id, $quantity, (int) ($costs[$line->product_id] ?? 0));
                }
            }

            if ($discrepancy && blank($note)) {
                throw new InvalidStockOperation(__('inventory.errors.discrepancy_reason'));
            }

            if ($movements !== []) {
                $this->ledger->post('transfer_receipt', $idempotencyKey, $movements, $actor, source: $locked, reason: $note);
            }

            $locked->status = StockTransfer::RECEIVED;
            $locked->received_by = $actor->id;
            $locked->received_at = now();
            $locked->receipt_note = $note;
            $locked->version++;
            $locked->save();

            $this->audit->record('stock.transfer_received', $locked, after: ['number' => $locked->number, 'discrepancy' => $discrepancy], metadata: array_filter(['reason' => $note]));

            return $locked;
        });
    }

    public function cancel(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($transfer, $actor): StockTransfer {
            $locked = $this->lock($transfer);
            // Après expédition, la marchandise a quitté le dépôt : il faut la recevoir.
            $this->expectStatus($locked, StockTransfer::DRAFT);

            $locked->status = StockTransfer::CANCELLED;
            $locked->cancelled_by = $actor->id;
            $locked->cancelled_at = now();
            $locked->version++;
            $locked->save();

            $this->audit->record('stock.transfer_cancelled', $locked);

            return $locked;
        });
    }

    /**
     * @param  list<array{product_id: string, quantity: string}>  $lines
     * @return array<string, Quantity>
     */
    private function normalize(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $quantity = Quantity::of($line['quantity']);

            if (! $quantity->isPositive()) {
                throw new InvalidStockOperation(__('inventory.errors.positive_quantity'));
            }

            $normalized[$line['product_id']] = isset($normalized[$line['product_id']])
                ? $normalized[$line['product_id']]->add($quantity)
                : $quantity;
        }

        if ($normalized === []) {
            throw new InvalidStockOperation(__('inventory.errors.no_lines'));
        }

        return $normalized;
    }

    private function lock(StockTransfer $transfer): StockTransfer
    {
        /** @var StockTransfer */
        return StockTransfer::query()->with('lines.product')->lockForUpdate()->findOrFail($transfer->id);
    }

    private function expectStatus(StockTransfer $transfer, string $status): void
    {
        if ($transfer->status !== $status) {
            throw new InvalidStockOperation(__('inventory.errors.transfer_status', ['status' => __("inventory.transfer_status.{$transfer->status}")]));
        }
    }

    private function ledgerKnows(string $idempotencyKey): bool
    {
        return StockOperation::query()->where('idempotency_key', $idempotencyKey)->exists();
    }
}
