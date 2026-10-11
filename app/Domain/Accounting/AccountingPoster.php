<?php

namespace App\Domain\Accounting;

use App\Domain\Inventory\Quantity;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingSetting;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\StockOperation;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Comptabilisation automatique des opérations métier validées (docs/05,
 * « écritures automatiques minimales »).
 *
 * Idempotente : chaque couple (source, nature) est noté dans accounting_postings
 * dans la même transaction que l'écriture ; rejouer ne crée jamais de doublon,
 * y compris pour une opération qui ne produit aucune écriture (montant nul).
 *
 * Schéma retenu, à faire valider par l'expert-comptable :
 * - inventaire permanent : réception D 311 / C 4081 ; facture fournisseur
 *   D 4081 (marchandises reçues) ou 605, D 4452 / C 401 ;
 * - vente : D 411 / C 701-706, C 4431 ; coût des ventes D 6031 / C 311 ;
 * - règlements via 411/401 contre le compte de trésorerie du mode de paiement.
 */
final class AccountingPoster
{
    /** @var array<string, string> */
    private array $mappings = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Comptabilise ce qui ne l'est pas encore. Renvoie le nombre d'opérations traitées.
     * Une opération dont la date n'a pas d'exercice reste en attente (réessai ultérieur).
     *
     * @return array{posted: int, pending: int}
     */
    public function postPending(int $limit = 500): array
    {
        $tenant = $this->context->tenant();

        // Formule sans comptabilité ou entreprise en lecture seule : rien n'est écrit.
        if (! $this->access->hasFeature($tenant, FeatureAccess::FEATURE_ACCOUNTING) || ! $this->access->canWrite($tenant)) {
            return ['posted' => 0, 'pending' => 0];
        }

        /** @var AccountingSetting|null $settings */
        $settings = AccountingSetting::query()->first();

        if ($settings === null) {
            return ['posted' => 0, 'pending' => 0];
        }

        $since = $settings->posting_starts_on;
        $posted = 0;
        $pending = 0;

        foreach ($this->sources($since) as [$query, $kinds]) {
            foreach ($query->limit($limit)->get() as $source) {
                foreach ($kinds as $kind) {
                    try {
                        $this->postOne($source, $kind) ? $posted++ : null;
                    } catch (InvalidAccountingOperation) {
                        $pending++; // pas d'exercice, période introuvable… : réessai au prochain passage
                    }
                }
            }
        }

        return ['posted' => $posted, 'pending' => $pending];
    }

    /**
     * Nombre d'opérations validées en attente de comptabilisation.
     */
    public function pendingCount(): int
    {
        /** @var AccountingSetting|null $settings */
        $settings = AccountingSetting::query()->first();

        if ($settings === null) {
            return 0;
        }

        $count = 0;

        foreach ($this->sources($settings->posting_starts_on) as [$query]) {
            $count += $query->count();
        }

        return $count;
    }

    /**
     * @return list<array{0: Builder<Model>, 1: list<string>}>
     */
    private function sources(CarbonImmutable $since): array
    {
        $tenantId = $this->context->tenantId();
        $notPosted = fn (string $type, string $kind) => fn (Builder $q) => $q->whereNotExists(fn ($sub) => $sub
            ->selectRaw('1')->from('accounting_postings')
            ->where('accounting_postings.tenant_id', $tenantId)
            ->where('accounting_postings.source_type', $type)
            ->where('accounting_postings.kind', $kind)
            ->whereColumn('accounting_postings.source_id', $q->getModel()->getTable().'.id'));

        $date = $since->toDateString();

        /** @var list<array{0: Builder<Model>, 1: list<string>}> */
        return [
            [SalesDocument::query()->where('type', SalesDocument::INVOICE)->where('status', SalesDocument::VALIDATED)
                ->whereDate('issue_date', '>=', $date)->where($notPosted('SalesDocument', 'cogs'))->orderBy('validated_at'), ['sale', 'cogs']],
            [SalesDocument::query()->where('type', SalesDocument::CREDIT_NOTE)->where('status', SalesDocument::VALIDATED)
                ->whereDate('issue_date', '>=', $date)->where($notPosted('SalesDocument', 'cogs_return'))->orderBy('validated_at'), ['credit_note', 'cogs_return']],
            [Payment::query()->where('paid_at', '>=', $date)->where($notPosted('Payment', 'payment'))->orderBy('paid_at'), ['payment']],
            [GoodsReceipt::query()->where('received_at', '>=', $date)->where($notPosted('GoodsReceipt', 'receipt'))->orderBy('received_at'), ['receipt']],
            [SupplierInvoice::query()->where('status', SupplierInvoice::VALIDATED)->whereDate('issue_date', '>=', $date)
                ->where($notPosted('SupplierInvoice', 'purchase'))->orderBy('validated_at'), ['purchase']],
            [SupplierReturn::query()->where('created_at', '>=', $date)->where($notPosted('SupplierReturn', 'supplier_return'))->orderBy('created_at'), ['supplier_return']],
            [CashMovement::query()->whereIn('type', ['expense', 'income'])->where('occurred_at', '>=', $date)
                ->where($notPosted('CashMovement', 'cash'))->orderBy('occurred_at'), ['cash']],
            [CashSession::query()->where('status', CashSession::CLOSED)->where('closed_at', '>=', $date)
                ->where($notPosted('CashSession', 'cash_difference'))->orderBy('closed_at'), ['cash_difference']],
            [StockOperation::query()->whereIn('type', ['initial', 'adjustment', 'count'])->where('occurred_at', '>=', $date)
                ->where($notPosted('StockOperation', 'stock'))->orderBy('occurred_at'), ['stock']],
        ];
    }

    /**
     * Traite une nature d'écriture pour une source. Vrai si une écriture a été passée.
     */
    private function postOne(Model $source, string $kind): bool
    {
        $type = class_basename($source);
        $id = (string) $source->getKey();

        if (DB::table('accounting_postings')->where('tenant_id', $this->context->tenantId())
            ->where('source_type', $type)->where('source_id', $id)->where('kind', $kind)->exists()) {
            return false;
        }

        $plan = $this->plan($source, $kind);

        try {
            return DB::transaction(function () use ($plan, $type, $id, $kind): bool {
                $entry = $plan !== null
                    ? $this->ledger->post($plan['journal'], $plan['date'], $plan['description'], $plan['lines'], null,
                        source: ['type' => $type, 'id' => $id, 'kind' => $kind], shiftToOpenPeriod: true)
                    : null;

                DB::table('accounting_postings')->insert([
                    'tenant_id' => $this->context->tenantId(), 'source_type' => $type, 'source_id' => $id,
                    'kind' => $kind, 'entry_id' => $entry?->id, 'created_at' => now(),
                ]);

                return $entry !== null;
            });
        } catch (UniqueConstraintViolationException) {
            return false; // traité en parallèle par un autre passage
        }
    }

    /**
     * Écriture à passer pour (source, nature), ou null si rien à comptabiliser.
     *
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function plan(Model $source, string $kind): ?array
    {
        return match (true) {
            $source instanceof SalesDocument && $kind === 'sale' => $this->sale($source, reverse: false),
            $source instanceof SalesDocument && $kind === 'credit_note' => $this->sale($source, reverse: true),
            $source instanceof SalesDocument && $kind === 'cogs' => $this->costOfSales($source, reverse: false),
            $source instanceof SalesDocument && $kind === 'cogs_return' => $this->costOfSales($source, reverse: true),
            $source instanceof Payment => $this->payment($source),
            $source instanceof GoodsReceipt => $this->receipt($source),
            $source instanceof SupplierInvoice => $this->purchase($source),
            $source instanceof SupplierReturn => $this->supplierReturn($source),
            $source instanceof CashMovement => $this->cashMovement($source),
            $source instanceof CashSession => $this->cashDifference($source),
            $source instanceof StockOperation => $this->stock($source),
            default => null,
        };
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function sale(SalesDocument $document, bool $reverse): ?array
    {
        if ($document->total === 0) {
            return null;
        }

        $lines = [];
        $party = $document->customer_id;
        $label = ($reverse ? __('accounting.labels.credit_note') : __('accounting.labels.invoice')).' '.$document->number;
        $lines[] = EntryLine::debit($this->code('customers'), $document->total, $label, $party ? 'customer' : null, $party);

        $revenue = SalesDocumentLine::query()->where('document_id', $document->id)
            ->selectRaw("CASE WHEN product_type = 'service' THEN 'services' ELSE 'goods' END AS kind, SUM(line_excl_tax) AS amount")
            ->groupByRaw("CASE WHEN product_type = 'service' THEN 'services' ELSE 'goods' END")->pluck('amount', 'kind');

        foreach (['goods' => 'sales_goods', 'services' => 'sales_services'] as $group => $mapping) {
            if ((int) ($revenue[$group] ?? 0) > 0) {
                $lines[] = EntryLine::credit($this->code($mapping), (int) $revenue[$group], $label);
            }
        }

        if ($document->total_tax > 0) {
            $lines[] = EntryLine::credit($this->code('vat_collected'), $document->total_tax, __('accounting.labels.vat').' '.$document->number);
        }

        return [
            'journal' => 'VT',
            'date' => CarbonImmutable::parse($document->issue_date ?? $document->validated_at),
            'description' => $label.(isset($document->customer_snapshot['name']) ? ' — '.$document->customer_snapshot['name'] : ''),
            'lines' => $reverse ? array_map(fn (EntryLine $l) => $l->reversed(), $lines) : $lines,
        ];
    }

    /**
     * Coût des ventes (D 6031 / C 311), au coût moyen figé sur les lignes ; avoir :
     * seules les lignes remises en stock.
     *
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function costOfSales(SalesDocument $document, bool $reverse): ?array
    {
        $value = 0;

        foreach (SalesDocumentLine::query()->where('document_id', $document->id)->where('product_type', '<>', 'service')
            ->when($reverse, fn ($q) => $q->where('restock', true))->get() as $line) {
            $value += Quantity::of($line->quantity)->times((int) $line->unit_cost);
        }

        if ($value === 0) {
            return null;
        }

        $label = __('accounting.labels.cost_of_sales').' '.$document->number;
        $lines = [
            EntryLine::debit($this->code('cost_of_sales'), $value, $label),
            EntryLine::credit($this->code('inventory'), $value, $label),
        ];

        return [
            'journal' => 'VT',
            'date' => CarbonImmutable::parse($document->issue_date ?? $document->validated_at),
            'description' => $label,
            'lines' => $reverse ? array_map(fn (EntryLine $l) => $l->reversed(), $lines) : $lines,
        ];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}
     */
    private function payment(Payment $payment): array
    {
        $treasury = $this->code("treasury_{$payment->method}");
        $label = __('accounting.labels.payment', ['number' => $payment->number, 'method' => __("sales.methods.{$payment->method}")]);

        [$debit, $credit] = match ($payment->kind) {
            'customer_payment' => [EntryLine::debit($treasury, $payment->amount, $label), EntryLine::credit($this->code('customers'), $payment->amount, $label, 'customer', $payment->customer_id)],
            'customer_refund' => [EntryLine::debit($this->code('customers'), $payment->amount, $label, 'customer', $payment->customer_id), EntryLine::credit($treasury, $payment->amount, $label)],
            'supplier_payment' => [EntryLine::debit($this->code('suppliers'), $payment->amount, $label, 'supplier', $payment->supplier_id), EntryLine::credit($treasury, $payment->amount, $label)],
            default => [EntryLine::debit($treasury, $payment->amount, $label), EntryLine::credit($this->code('suppliers'), $payment->amount, $label, 'supplier', $payment->supplier_id)],
        };

        return [
            'journal' => $payment->method === 'cash' ? 'CA' : 'BQ',
            'date' => CarbonImmutable::parse($payment->paid_at),
            'description' => $label,
            'lines' => [$debit, $credit],
        ];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function receipt(GoodsReceipt $receipt): ?array
    {
        if ($receipt->total_excl_tax === 0) {
            return null;
        }

        $label = __('accounting.labels.receipt').' '.$receipt->number;

        return [
            'journal' => 'AC',
            'date' => CarbonImmutable::parse($receipt->received_at),
            'description' => $label,
            'lines' => [
                EntryLine::debit($this->code('inventory'), $receipt->total_excl_tax, $label),
                EntryLine::credit($this->code('goods_received_not_invoiced'), $receipt->total_excl_tax, $label),
            ],
        ];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function purchase(SupplierInvoice $invoice): ?array
    {
        if ($invoice->total === 0) {
            return null;
        }

        $label = __('accounting.labels.supplier_invoice').' '.$invoice->number.($invoice->supplier_reference ? " ({$invoice->supplier_reference})" : '');
        // Marchandises déjà entrées en stock (réception) : on solde 4081 ; sinon charge directe.
        $received = $invoice->goods_receipt_id !== null || $invoice->purchase_order_id !== null;
        $goods = 0;
        $other = 0;

        foreach (SupplierInvoiceLine::query()->where('invoice_id', $invoice->id)->get() as $line) {
            $line->product_id !== null && $received ? $goods += $line->line_excl_tax : $other += $line->line_excl_tax;
        }

        $lines = [];

        if ($goods > 0) {
            $lines[] = EntryLine::debit($this->code('goods_received_not_invoiced'), $goods, $label);
        }

        if ($other > 0) {
            $lines[] = EntryLine::debit($this->code('purchases_other'), $other, $label);
        }

        if ($invoice->total_tax > 0) {
            $lines[] = EntryLine::debit($this->code('vat_deductible'), $invoice->total_tax, __('accounting.labels.vat').' '.$invoice->number);
        }

        $lines[] = EntryLine::credit($this->code('suppliers'), $invoice->total, $label, 'supplier', $invoice->supplier_id);

        return ['journal' => 'AC', 'date' => CarbonImmutable::parse($invoice->issue_date ?? $invoice->validated_at), 'description' => $label, 'lines' => $lines];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function supplierReturn(SupplierReturn $return): ?array
    {
        if ($return->total === 0) {
            return null;
        }

        $label = __('accounting.labels.supplier_return').' '.$return->number;
        $lines = $return->supplier_invoice_id !== null
            ? [
                EntryLine::debit($this->code('suppliers'), $return->total, $label, 'supplier', $return->supplier_id),
                EntryLine::credit($this->code('inventory'), $return->total_excl_tax, $label),
                ...($return->total_tax > 0 ? [EntryLine::credit($this->code('vat_deductible'), $return->total_tax, $label)] : []),
            ]
            : [
                EntryLine::debit($this->code('goods_received_not_invoiced'), $return->total_excl_tax, $label),
                EntryLine::credit($this->code('inventory'), $return->total_excl_tax, $label),
            ];

        return ['journal' => 'AC', 'date' => CarbonImmutable::parse($return->created_at), 'description' => $label, 'lines' => $lines];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}
     */
    private function cashMovement(CashMovement $movement): array
    {
        $amount = abs($movement->amount);
        $label = trim(($movement->number ?? '').' '.($movement->label ?? '')).($movement->reason ? ' — '.$movement->reason : '');
        $cash = $this->code('treasury_cash');

        $lines = $movement->type === 'expense'
            ? [EntryLine::debit($this->code('cash_expense'), $amount, $label), EntryLine::credit($cash, $amount, $label)]
            : [EntryLine::debit($cash, $amount, $label), EntryLine::credit($this->code('cash_income'), $amount, $label)];

        return ['journal' => 'CA', 'date' => CarbonImmutable::parse($movement->occurred_at), 'description' => $label, 'lines' => $lines];
    }

    /**
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function cashDifference(CashSession $session): ?array
    {
        $difference = (int) $session->difference;

        if ($difference === 0) {
            return null;
        }

        $label = __('accounting.labels.cash_difference').($session->closing_note ? ' — '.$session->closing_note : '');
        $cash = $this->code('treasury_cash');
        $amount = abs($difference);

        $lines = $difference < 0
            ? [EntryLine::debit($this->code('cash_shortage'), $amount, $label), EntryLine::credit($cash, $amount, $label)]
            : [EntryLine::debit($cash, $amount, $label), EntryLine::credit($this->code('cash_overage'), $amount, $label)];

        return ['journal' => 'CA', 'date' => CarbonImmutable::parse($session->closed_at), 'description' => $label, 'lines' => $lines];
    }

    /**
     * Stock d'ouverture et écarts (ajustements, inventaires), valorisés au coût du mouvement.
     *
     * @return array{journal: string, date: CarbonImmutable, description: string, lines: list<EntryLine>}|null
     */
    private function stock(StockOperation $operation): ?array
    {
        $gain = 0;
        $loss = 0;

        foreach (InventoryMovement::query()->where('operation_id', $operation->id)->get() as $movement) {
            $quantity = Quantity::of($movement->quantity_delta);
            $value = abs($quantity->times($movement->unit_cost));
            $quantity->isPositive() ? $gain += $value : $loss += $value;
        }

        $label = __("accounting.labels.stock_{$operation->type}").($operation->reason ? ' — '.$operation->reason : '');
        $inventory = $this->code('inventory');
        $lines = [];

        if ($gain > 0) {
            $counterpart = $operation->type === 'initial' ? 'opening_balance' : 'inventory_gain';
            $lines[] = EntryLine::debit($inventory, $gain, $label);
            $lines[] = EntryLine::credit($this->code($counterpart), $gain, $label);
        }

        if ($loss > 0) {
            $lines[] = EntryLine::debit($this->code('inventory_loss'), $loss, $label);
            $lines[] = EntryLine::credit($inventory, $loss, $label);
        }

        return $lines === [] ? null : ['journal' => 'IN', 'date' => CarbonImmutable::parse($operation->occurred_at), 'description' => $label, 'lines' => $lines];
    }

    private function code(string $mapping): string
    {
        if ($this->mappings === []) {
            $this->mappings = DB::table('account_mappings')
                ->join('chart_accounts', 'chart_accounts.id', '=', 'account_mappings.account_id')
                ->where('account_mappings.tenant_id', $this->context->tenantId())
                ->pluck('chart_accounts.code', 'account_mappings.key')
                ->map(fn ($code) => (string) $code)
                ->all();
        }

        return $this->mappings[$mapping] ?? throw new InvalidAccountingOperation(__('accounting.errors.missing_mapping', ['key' => $mapping]));
    }
}
