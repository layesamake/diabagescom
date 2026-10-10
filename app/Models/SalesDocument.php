<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document de vente : devis, commande, facture (y compris vente comptoir) ou avoir.
 * Brouillon modifiable → validé (numéroté, figé). Montants XOF entiers, TTC.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string|null $number
 * @property string $status
 * @property string $channel
 * @property string $warehouse_id
 * @property string|null $customer_id
 * @property array<string, mixed>|null $customer_snapshot
 * @property string|null $source_document_id
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $valid_until
 * @property int $total_gross
 * @property int $total_discount
 * @property int $total_excl_tax
 * @property int $total_tax
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_credited
 * @property string|null $payment_status
 * @property string|null $reason
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property string|null $negative_stock_reason
 * @property string $created_by
 * @property string|null $validated_by
 * @property CarbonImmutable|null $validated_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property-read Warehouse $warehouse
 * @property-read Customer|null $customer
 * @property-read SalesDocument|null $source
 * @property-read User $author
 */
class SalesDocument extends Model
{
    use BelongsToTenant, HasUuids;

    public const string QUOTE = 'quote';

    public const string ORDER = 'order';

    public const string INVOICE = 'invoice';

    public const string CREDIT_NOTE = 'credit_note';

    public const array TYPES = [self::QUOTE, self::ORDER, self::INVOICE, self::CREDIT_NOTE];

    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    public const string CONVERTED = 'converted';

    public const string CANCELLED = 'cancelled';

    /** Préfixes de numérotation par type (séquence par entreprise, type et année). */
    public const array PREFIXES = [
        self::QUOTE => 'DV',
        self::ORDER => 'CC',
        self::INVOICE => 'FA',
        self::CREDIT_NOTE => 'AV',
    ];

    protected $guarded = [];

    protected $attributes = ['status' => self::DRAFT, 'channel' => 'backoffice', 'version' => 1];

    protected function casts(): array
    {
        return [
            'customer_snapshot' => 'array',
            'issue_date' => 'date',
            'due_date' => 'date',
            'valid_until' => 'date',
            'total_gross' => 'integer',
            'total_discount' => 'integer',
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

    /**
     * Reste à payer (négatif : trop-perçu à rembourser après un avoir).
     */
    public function balanceDue(): int
    {
        return $this->total - $this->amount_paid - $this->amount_credited;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * @return HasMany<SalesDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class, 'document_id')->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'sales_document_id');
    }

    /**
     * @return HasMany<SalesDocument, $this>
     */
    public function derived(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'source_document_id');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'source_document_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
