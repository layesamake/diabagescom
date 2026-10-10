<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de réception (append-only) : la marchandise entre en stock au coût d'achat HT.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $warehouse_id
 * @property string $supplier_id
 * @property string|null $purchase_order_id
 * @property string|null $supplier_reference
 * @property string|null $note
 * @property int $total_excl_tax
 * @property string $idempotency_key
 * @property string $created_by
 * @property CarbonImmutable $received_at
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User $author
 */
class GoodsReceipt extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total_excl_tax' => 'integer', 'received_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'receipt_id');
    }

    /**
     * @return HasMany<SupplierReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
