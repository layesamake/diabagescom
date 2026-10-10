<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture fournisseur : sa validation crée la dette envers le fournisseur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property string|null $supplier_reference
 * @property string $status
 * @property string $supplier_id
 * @property string $warehouse_id
 * @property string|null $purchase_order_id
 * @property string|null $goods_receipt_id
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property int $total_excl_tax
 * @property int $total_tax
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_credited
 * @property string|null $payment_status
 * @property string|null $notes
 * @property string $created_by
 * @property string|null $validated_by
 * @property CarbonImmutable|null $validated_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read GoodsReceipt|null $goodsReceipt
 */
class SupplierInvoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    public const string CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'total_excl_tax' => 'integer',
            'total_tax' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'amount_credited' => 'integer',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function balanceDue(): int
    {
        return $this->total - $this->amount_paid - $this->amount_credited;
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'invoice_id')->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
