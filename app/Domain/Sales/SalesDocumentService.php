<?php

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\InvalidStockOperation;
use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Inventory\StockLedger;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Devis, commandes et factures (docs/02 §5) :
 * - brouillon librement modifiable (verrou optimiste), lignes = instantanés du catalogue ;
 * - validation : numéro unique attribué, montants figés (trigger), sortie de stock
 *   pour une facture, contrôle du crédit client ;
 * - conversion devis → commande → facture en conservant le lien d'origine ;
 * - une facture validée ne s'annule que par un avoir (CreditNoteService).
 */
final class SalesDocumentService
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
     * Crée ou remplace un brouillon.
     *
     * @param  array{warehouse_id: string, customer_id?: string|null, valid_until?: string|null, notes?: string|null, version?: int|null, channel?: string, idempotency_key?: string|null, lines: list<array{product_id: string, quantity: string, unit_price?: int|null, discount?: int|null}>}  $data
     */
    public function saveDraft(?SalesDocument $document, string $type, array $data, User $actor): SalesDocument
    {
        if (! in_array($type, [SalesDocument::QUOTE, SalesDocument::ORDER, SalesDocument::INVOICE], true)) {
            throw new InvalidArgumentException("Unsupported draft type [{$type}].");
        }

        $this->access->assertCanWrite($this->context->tenant());
        $warehouse = $this->stockAccess->warehouse($data['warehouse_id']);
        $customer = $this->customer($data['customer_id'] ?? null);

        return DB::transaction(function () use ($document, $type, $data, $actor, $warehouse, $customer): SalesDocument {
            if ($document !== null) {
                $document = $this->lock($document);
                $this->expectDraft($document);

                if (isset($data['version']) && $data['version'] !== $document->version) {
                    throw new InvalidSalesOperation(__('sales.errors.stale'));
                }

                $this->stockAccess->warehouse($document->warehouse_id);
                $document->lines()->delete();
                $document->version++;
            } else {
                $document = new SalesDocument;
                $document->type = $type;
                $document->created_by = $actor->id;
                $document->channel = $data['channel'] ?? 'backoffice';
                $document->idempotency_key = $data['idempotency_key'] ?? null;
            }

            $document->warehouse_id = $warehouse->id;
            $document->customer_id = $customer?->id;
            $document->valid_until = $type === SalesDocument::QUOTE && filled($data['valid_until'] ?? null) ? CarbonImmutable::parse((string) $data['valid_until']) : null;
            $document->notes = $data['notes'] ?? null;
            $document->save();

            $this->writeLines($document, $data['lines'], $actor);
            $this->refreshTotals($document);

            $this->audit->record("sales.{$type}_draft_saved", $document, after: ['lines' => count($data['lines']), 'total' => $document->total]);

            return $document;
        });
    }

    /**
     * Valide un brouillon. Rejouer la validation d'un document déjà validé ne refait rien.
     *
     * @param  int  $upfrontPayment  montant encaissé dans la même opération (vente comptoir)
     */
    public function validate(SalesDocument $document, User $actor, ?string $negativeStockReason = null, int $upfrontPayment = 0): SalesDocument
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($document, $actor, $negativeStockReason, $upfrontPayment): SalesDocument {
            $document = $this->lock($document);

            if ($document->status === SalesDocument::VALIDATED) {
                return $document;
            }

            $this->expectDraft($document);
            $this->stockAccess->warehouse($document->warehouse_id);
            $document->load('lines', 'customer');

            if ($document->lines->isEmpty()) {
                throw new InvalidSalesOperation(__('sales.errors.no_lines'));
            }

            $this->refreshTotals($document);
            $today = now()->toImmutable()->startOfDay();

            if ($document->type === SalesDocument::INVOICE) {
                if ($upfrontPayment > $document->total) {
                    throw new InvalidSalesOperation(__('sales.errors.overpayment'));
                }

                $this->assertCredit($document, $document->total - $upfrontPayment);
                $this->postStockExit($document, $actor, $negativeStockReason);
                $document->due_date = $today->addDays($document->customer->payment_term_days ?? 0);
                $document->payment_status = $document->total === 0 ? 'paid' : 'unpaid';
                $document->negative_stock_reason = filled($negativeStockReason) ? trim((string) $negativeStockReason) : null;
            }

            if ($document->type === SalesDocument::QUOTE && $document->valid_until === null) {
                $document->valid_until = $today->addDays((int) config('diaba.sales.quote_validity_days', 30));
            }

            $document->customer_snapshot = $this->snapshot($document->customer);
            $document->issue_date = $today;
            $document->number = $this->sequences->document("sales:{$document->type}", SalesDocument::PREFIXES[$document->type]);
            $document->status = SalesDocument::VALIDATED;
            $document->validated_by = $actor->id;
            $document->validated_at = now();
            $document->version++;
            $document->save();

            $this->audit->record("sales.{$document->type}_validated", $document,
                after: ['number' => $document->number, 'total' => $document->total],
                metadata: array_filter(['negative_stock_reason' => $document->negative_stock_reason]),
                actor: $actor);

            return $document;
        });
    }

    /**
     * Devis → commande ou facture ; commande → facture. Le nouveau document est un
     * brouillon aux mêmes prix (ceux du devis), lié à son origine.
     */
    public function convert(SalesDocument $source, string $targetType, User $actor): SalesDocument
    {
        $this->access->assertCanWrite($this->context->tenant());

        $allowed = [SalesDocument::QUOTE => [SalesDocument::ORDER, SalesDocument::INVOICE], SalesDocument::ORDER => [SalesDocument::INVOICE]];

        if (! in_array($targetType, $allowed[$source->type] ?? [], true)) {
            throw new InvalidSalesOperation(__('sales.errors.conversion'));
        }

        return DB::transaction(function () use ($source, $targetType, $actor): SalesDocument {
            $source = $this->lock($source);
            $this->stockAccess->warehouse($source->warehouse_id);

            // Rejeu : la conversion existe déjà.
            if ($source->status === SalesDocument::CONVERTED) {
                $existing = SalesDocument::query()->where('source_document_id', $source->id)
                    ->where('type', $targetType)->where('status', '<>', SalesDocument::CANCELLED)->first();

                return $existing ?? throw new InvalidSalesOperation(__('sales.errors.already_converted'));
            }

            if ($source->status !== SalesDocument::VALIDATED) {
                throw new InvalidSalesOperation(__('sales.errors.convert_status'));
            }

            $target = new SalesDocument;
            $target->type = $targetType;
            $target->warehouse_id = $source->warehouse_id;
            $target->customer_id = $source->customer_id;
            $target->source_document_id = $source->id;
            $target->notes = $source->notes;
            $target->created_by = $actor->id;
            $target->save();

            foreach ($source->lines()->get() as $line) {
                $copy = $line->replicate(['id', 'document_id', 'unit_cost', 'source_line_id', 'restock', 'created_at', 'updated_at']);
                $copy->document_id = $target->id;
                $copy->save();
            }

            $this->refreshTotals($target);

            $source->status = SalesDocument::CONVERTED;
            $source->version++;
            $source->save();

            $this->audit->record("sales.{$source->type}_converted", $source, after: ['to' => $targetType, 'document_id' => $target->id], actor: $actor);

            return $target;
        });
    }

    /**
     * Annule un brouillon, ou un devis/une commande validés (motif requis).
     */
    public function cancel(SalesDocument $document, ?string $reason, User $actor): SalesDocument
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($document, $reason, $actor): SalesDocument {
            $document = $this->lock($document);
            $this->stockAccess->warehouse($document->warehouse_id);

            if ($document->status === SalesDocument::CANCELLED) {
                return $document;
            }

            $validatedCommitment = $document->status === SalesDocument::VALIDATED && in_array($document->type, [SalesDocument::QUOTE, SalesDocument::ORDER], true);

            if (! $document->isDraft() && ! $validatedCommitment) {
                throw new InvalidSalesOperation(__($document->type === SalesDocument::INVOICE ? 'sales.errors.use_credit_note' : 'sales.errors.cancel_status'));
            }

            if ($validatedCommitment && mb_strlen(trim((string) $reason)) < 3) {
                throw new InvalidSalesOperation(__('sales.errors.reason_required'));
            }

            $document->status = SalesDocument::CANCELLED;
            $document->cancelled_by = $actor->id;
            $document->cancelled_at = now();
            $document->cancel_reason = filled($reason) ? trim((string) $reason) : null;
            $document->version++;
            $document->save();

            $this->audit->record("sales.{$document->type}_cancelled", $document, metadata: array_filter(['reason' => $document->cancel_reason]), actor: $actor);

            return $document;
        });
    }

    /**
     * Solde dû par un client sur ses factures validées (hors document exclu).
     */
    public function outstanding(string $customerId, ?string $exceptDocumentId = null): int
    {
        return (int) SalesDocument::query()
            ->where('customer_id', $customerId)
            ->where('type', SalesDocument::INVOICE)
            ->where('status', SalesDocument::VALIDATED)
            ->when($exceptDocumentId !== null, fn ($q) => $q->whereKeyNot($exceptDocumentId))
            ->sum(DB::raw('GREATEST(total - amount_paid - amount_credited, 0)'));
    }

    public function refreshPaymentStatus(SalesDocument $invoice): void
    {
        $balance = $invoice->balanceDue();

        $invoice->payment_status = match (true) {
            $balance < 0 => 'refund_due',
            $balance === 0 => 'paid',
            $invoice->amount_paid + $invoice->amount_credited === 0 => 'unpaid',
            default => 'partial',
        };
    }

    public function lock(SalesDocument $document): SalesDocument
    {
        /** @var SalesDocument */
        return SalesDocument::query()->lockForUpdate()->findOrFail($document->id);
    }

    /**
     * @param  list<array{product_id: string, quantity: string, unit_price?: int|null, discount?: int|null}>  $lines
     */
    private function writeLines(SalesDocument $document, array $lines, User $actor): void
    {
        if ($lines === []) {
            throw new InvalidSalesOperation(__('sales.errors.no_lines'));
        }

        $products = Product::query()->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()->keyBy('id');
        $user = Gate::forUser($actor);
        $standardMax = (int) config('diaba.sales.standard_discount_max_bp', 1000);

        foreach ($lines as $position => $input) {
            $product = $products[$input['product_id']] ?? throw new InvalidSalesOperation(__('sales.errors.unknown_product'));

            if ($product->archived_at !== null || ! $product->active) {
                throw new InvalidSalesOperation(__('sales.errors.archived_product', ['product' => $product->name]));
            }

            try {
                $quantity = Quantity::of($input['quantity']);
            } catch (InvalidArgumentException $e) {
                throw new InvalidSalesOperation($e->getMessage());
            }

            if (! $quantity->isPositive()) {
                throw new InvalidSalesOperation(__('sales.errors.positive_quantity', ['product' => $product->name]));
            }

            if (! $product->unit->decimals_allowed && ! $quantity->isWhole()) {
                throw new InvalidStockOperation(__('inventory.errors.whole_quantity', ['product' => $product->name]));
            }

            $unitPrice = $input['unit_price'] ?? $product->sale_price;

            if ($unitPrice < 0) {
                throw new InvalidSalesOperation(__('sales.errors.price_negative'));
            }

            // Prix catalogue modifiable seulement par permission (docs/02 §5).
            if ($unitPrice !== $product->sale_price && ! $user->allows('catalog.price_override')) {
                throw new InvalidSalesOperation(__('sales.errors.price_override', ['product' => $product->name]));
            }

            $discount = (int) ($input['discount'] ?? 0);
            $amounts = Pricing::saleLine($quantity, $unitPrice, $discount, $product->taxRate->rate_basis_points);

            // Remise standard jusqu'au plafond ; au-delà, permission d'exception.
            if ($discount > 0) {
                $withinStandard = $discount * 10000 <= $amounts['gross'] * $standardMax;
                $allowed = $user->allows('sale.discount_exception') || ($withinStandard && $user->allows('sale.discount_standard'));

                if (! $allowed) {
                    throw new InvalidSalesOperation(__($withinStandard ? 'sales.errors.discount_forbidden' : 'sales.errors.discount_exceeds', [
                        'product' => $product->name, 'max' => intdiv($standardMax, 100),
                    ]));
                }
            }

            $line = new SalesDocumentLine;
            $line->forceFill([
                'document_id' => $document->id,
                'position' => $position + 1,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'label' => $product->name,
                'unit_code' => $product->unit->code,
                'product_type' => $product->type,
                'quantity' => $quantity->value,
                'unit_price' => $unitPrice,
                'discount_amount' => $amounts['discount'],
                'tax_rate_basis_points' => $product->taxRate->rate_basis_points,
                'line_total' => $amounts['total'],
                'line_excl_tax' => $amounts['excl_tax'],
                'line_tax' => $amounts['tax'],
            ])->save();
        }
    }

    public function refreshTotals(SalesDocument $document): void
    {
        $totals = SalesDocumentLine::query()->where('document_id', $document->id)
            ->selectRaw('COALESCE(SUM(line_total + discount_amount), 0) AS gross, COALESCE(SUM(discount_amount), 0) AS discount,
                COALESCE(SUM(line_excl_tax), 0) AS excl, COALESCE(SUM(line_tax), 0) AS tax, COALESCE(SUM(line_total), 0) AS total')
            ->first();

        $document->total_gross = (int) $totals?->getAttribute('gross');
        $document->total_discount = (int) $totals?->getAttribute('discount');
        $document->total_excl_tax = (int) $totals?->getAttribute('excl');
        $document->total_tax = (int) $totals?->getAttribute('tax');
        $document->total = (int) $totals?->getAttribute('total');
        $document->save();
    }

    /**
     * Vente à crédit : client identifié, plafond défini et non dépassé.
     * La ligne client est verrouillée pour sérialiser les ventes à crédit simultanées.
     */
    private function assertCredit(SalesDocument $document, int $due): void
    {
        if ($due <= 0) {
            return;
        }

        if ($document->customer_id === null) {
            throw new InvalidSalesOperation(__('sales.errors.credit_requires_customer'));
        }

        /** @var Customer $customer */
        $customer = Customer::query()->lockForUpdate()->findOrFail($document->customer_id);

        if ($customer->credit_limit === null) {
            throw new InvalidSalesOperation(__('sales.errors.no_credit', ['customer' => $customer->name]));
        }

        $outstanding = $this->outstanding($customer->id, $document->id);

        if ($outstanding + $due > $customer->credit_limit) {
            throw new InvalidSalesOperation(__('sales.errors.credit_exceeded', [
                'customer' => $customer->name,
                'limit' => number_format($customer->credit_limit, 0, ',', ' '),
                'outstanding' => number_format($outstanding, 0, ',', ' '),
                'due' => number_format($due, 0, ',', ' '),
            ]));
        }
    }

    /**
     * Sortie de stock de la facture (mouvement unique, clé dérivée du document) et
     * coût moyen figé sur chaque ligne pour la marge et la comptabilité.
     */
    private function postStockExit(SalesDocument $document, User $actor, ?string $negativeStockReason): void
    {
        $stocked = $document->lines->filter(fn (SalesDocumentLine $l) => $l->product_type !== 'service');

        if ($stocked->isEmpty()) {
            return;
        }

        $allowNegative = filled($negativeStockReason);

        if ($allowNegative && ! Gate::forUser($actor)->allows('sale.sell_negative_stock')) {
            throw new InvalidSalesOperation(__('sales.errors.negative_forbidden'));
        }

        $lines = $stocked->map(fn (SalesDocumentLine $l) => new MovementLine(
            $document->warehouse_id, $l->product_id, Quantity::of($l->quantity)->negate(),
        ))->values()->all();
        /** @var list<MovementLine> $lines */
        $operation = $this->ledger->post('sale', "sales_document:{$document->id}", $lines, $actor,
            source: $document, reason: $allowNegative ? trim((string) $negativeStockReason) : null, allowNegative: $allowNegative);

        $costs = InventoryMovement::query()->where('operation_id', $operation->id)->pluck('unit_cost', 'product_id');

        foreach ($stocked as $line) {
            $line->unit_cost = (int) ($costs[$line->product_id] ?? 0);
            $line->save();
        }
    }

    private function customer(?string $customerId): ?Customer
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        return Customer::query()->whereNull('archived_at')->find($customerId)
            ?? throw new InvalidSalesOperation(__('sales.errors.unknown_customer'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(?Customer $customer): ?array
    {
        return $customer?->only(['code', 'name', 'phone', 'email', 'address_line', 'city', 'tax_number']);
    }

    private function expectDraft(SalesDocument $document): void
    {
        if (! $document->isDraft()) {
            throw new InvalidSalesOperation(__('sales.errors.not_draft'));
        }
    }
}
