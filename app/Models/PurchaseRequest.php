<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande d'achat : besoin exprimé par un collaborateur, approuvé puis transformé
 * en commande fournisseur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property string $status
 * @property string $warehouse_id
 * @property CarbonImmutable|null $needed_on
 * @property string|null $notes
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property string|null $order_id
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Warehouse $warehouse
 * @property-read User $author
 */
class PurchaseRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const string DRAFT = 'draft';

    public const string SUBMITTED = 'submitted';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string ORDERED = 'ordered';

    public const string CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return [
            'needed_on' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<PurchaseRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'request_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}
