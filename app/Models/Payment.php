<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encaissement ou décaissement (append-only), rattaché à son document source.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $direction
 * @property string $kind
 * @property string $method
 * @property int $amount
 * @property string $warehouse_id
 * @property string|null $customer_id
 * @property string|null $supplier_id
 * @property string|null $sales_document_id
 * @property string|null $supplier_invoice_id
 * @property string|null $cash_session_id
 * @property string|null $reference
 * @property string|null $note
 * @property string $idempotency_key
 * @property string $created_by
 * @property CarbonImmutable $paid_at
 * @property-read User $author
 * @property-read SalesDocument|null $salesDocument
 * @property-read SupplierInvoice|null $supplierInvoice
 */
class Payment extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const array METHODS = ['cash', 'wave', 'orange_money', 'card', 'transfer', 'cheque'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
