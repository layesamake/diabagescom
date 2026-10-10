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
 * Retour fournisseur (append-only) : sortie de stock et avoir fournisseur
 * imputé sur la facture liée.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $supplier_id
 * @property string $warehouse_id
 * @property string $goods_receipt_id
 * @property string|null $supplier_invoice_id
 * @property string $reason
 * @property int $total_excl_tax
 * @property int $total_tax
 * @property int $total
 * @property string $idempotency_key
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class SupplierReturn extends Model
{
    use AppendOnly, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total_excl_tax' => 'integer', 'total_tax' => 'integer', 'total' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return HasMany<SupplierReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class, 'return_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
