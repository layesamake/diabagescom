<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande fournisseur : brouillon → confirmée → partiellement reçue → reçue
 * (ou soldée / annulée). Coûts HT en XOF entiers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property string $status
 * @property string $warehouse_id
 * @property string $supplier_id
 * @property CarbonImmutable|null $expected_on
 * @property string|null $notes
 * @property int $total_excl_tax
 * @property int $total_tax
 * @property int $total
 * @property string $created_by
 * @property string|null $validated_by
 * @property CarbonImmutable|null $validated_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string CONFIRMED = 'confirmed';

    public const string PARTIALLY_RECEIVED = 'partially_received';

    public const string RECEIVED = 'received';

    public const string CLOSED = 'closed';

    public const string CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return [
            'expected_on' => 'date',
            'total_excl_tax' => 'integer',
            'total_tax' => 'integer',
            'total' => 'integer',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'order_id')->orderBy('position');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * @return HasMany<SupplierInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
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
}
