<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentService;
use App\Domain\Sales\SalesDocumentPdf;
use App\Domain\Sales\SalesDocumentService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesDocumentController extends Controller
{
    private const array DRAFT_TYPES = [SalesDocument::QUOTE, SalesDocument::ORDER, SalesDocument::INVOICE];

    public function index(Request $request, StockAccess $access): Response
    {
        Gate::authorize('sale.view');

        $filters = [
            'type' => in_array($request->query('type'), SalesDocument::TYPES, true) ? (string) $request->query('type') : SalesDocument::INVOICE,
            'status' => in_array($request->query('status'), ['draft', 'validated', 'converted', 'cancelled', 'unpaid'], true) ? (string) $request->query('status') : '',
            'q' => trim((string) $request->query('q', '')),
        ];

        $documents = SalesDocument::query()
            ->with(['warehouse:id,code,name', 'customer:id,code,name'])
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->where('type', $filters['type'])
            ->when($filters['status'] === 'unpaid', fn ($q) => $q->where('status', 'validated')->whereIn('payment_status', ['unpaid', 'partial']))
            ->when(! in_array($filters['status'], ['', 'unpaid'], true), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('upper(number) LIKE upper(?)', ['%'.addcslashes($filters['q'], '%_\\').'%'])
                ->orWhereHas('customer', fn ($c) => $c->whereRaw('diaba_search_text(name) LIKE diaba_search_text(?)', ['%'.addcslashes($filters['q'], '%_\\').'%']))))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SalesDocument $d) => $this->summary($d));

        return Inertia::render('sales/Documents', [
            'documents' => $documents,
            'filters' => $filters,
            'can' => ['create' => Gate::allows('sale.create'), 'pos' => Gate::allows('sale.create') && Gate::allows('sale.validate')],
        ]);
    }

    public function create(string $type, StockAccess $access): Response
    {
        Gate::authorize('sale.create');
        abort_unless(in_array($type, self::DRAFT_TYPES, true), 404);

        return Inertia::render('sales/DocumentForm', [
            'type' => $type,
            'document' => null,
            ...$this->formData($access),
        ]);
    }

    public function store(Request $request, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.create');
        $data = $this->validateDraft($request);

        /** @var User $user */
        $user = $request->user();
        $document = $service->saveDraft(null, $data['type'], $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.draft')]);

        return to_route('sales.show', $document);
    }

    public function edit(SalesDocument $document, StockAccess $access): Response|RedirectResponse
    {
        Gate::authorize('sale.update_draft');
        $this->authorizeWarehouse($document, $access);

        if (! $document->isDraft()) {
            return to_route('sales.show', $document);
        }

        $document->load(['lines.product.unit', 'customer:id,code,name,credit_limit']);

        return Inertia::render('sales/DocumentForm', [
            'type' => $document->type,
            'document' => [
                'id' => $document->id,
                'version' => $document->version,
                'warehouse_id' => $document->warehouse_id,
                'customer' => $document->customer?->only(['id', 'code', 'name']),
                'valid_until' => $document->valid_until?->toDateString(),
                'notes' => $document->notes,
                'lines' => $document->lines->map(fn (SalesDocumentLine $l) => [
                    'product' => ['id' => $l->product_id, 'sku' => $l->sku, 'name' => $l->label, 'unit' => $l->unit_code, 'decimals' => $l->product->unit->decimals_allowed, 'sale_price' => $l->product->sale_price, 'tax_rate_basis_points' => $l->tax_rate_basis_points],
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'unit_price' => $l->unit_price,
                    'discount' => $l->discount_amount,
                ])->values(),
            ],
            ...$this->formData($access),
        ]);
    }

    public function update(Request $request, SalesDocument $document, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.update_draft');
        $data = $this->validateDraft($request, $document->type);

        /** @var User $user */
        $user = $request->user();
        $service->saveDraft($document, $document->type, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.draft')]);

        return to_route('sales.show', $document);
    }

    public function show(Request $request, SalesDocument $document, StockAccess $access, CreditNoteService $credits): Response
    {
        Gate::authorize('sale.view');
        $this->authorizeWarehouse($document, $access);

        $document->load(['lines.product.unit', 'warehouse:id,code,name', 'customer:id,code,name,phone,email', 'source:id,type,number', 'author:id,name',
            'payments' => fn ($q) => $q->with('author:id,name')->orderBy('created_at'),
            'derived' => fn ($q) => $q->select(['id', 'type', 'number', 'status', 'total', 'source_document_id'])->orderBy('created_at')]);

        $credited = $document->type === SalesDocument::INVOICE ? $credits->alreadyCredited($document) : [];
        $isInvoice = $document->type === SalesDocument::INVOICE && $document->status === SalesDocument::VALIDATED;
        $showCost = Gate::allows('report.margin_view');

        return Inertia::render('sales/DocumentShow', [
            'document' => [
                ...$this->summary($document),
                'notes' => $document->notes,
                'reason' => $document->reason,
                'cancel_reason' => $document->cancel_reason,
                'negative_stock_reason' => $document->negative_stock_reason,
                'issue_date' => $document->issue_date?->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'valid_until' => $document->valid_until?->toDateString(),
                'total_gross' => $document->total_gross,
                'total_discount' => $document->total_discount,
                'total_excl_tax' => $document->total_excl_tax,
                'total_tax' => $document->total_tax,
                'amount_paid' => $document->amount_paid,
                'amount_credited' => $document->amount_credited,
                'balance' => $document->balanceDue(),
                'author' => $document->author->name,
                // Coordonnées du client pour proposer le partage (WhatsApp, email).
                'contact' => ['phone' => $document->customer?->phone, 'email' => $document->customer?->email],
                'source' => $document->source?->only(['id', 'type', 'number']),
                'derived' => $document->derived->map->only(['id', 'type', 'number', 'status', 'total'])->values(),
                'lines' => $document->lines->map(function (SalesDocumentLine $l) use ($credited, $showCost) {
                    $creditedQuantity = isset($credited[$l->id]) ? $credited[$l->id]['quantity'] : Quantity::zero();

                    return [
                        'id' => $l->id,
                        'sku' => $l->sku,
                        'label' => $l->label,
                        'unit' => $l->unit_code,
                        'decimals' => $l->product->unit->decimals_allowed,
                        'stockable' => $l->product_type !== 'service',
                        'quantity' => Quantity::of($l->quantity)->format(),
                        'unit_price' => $l->unit_price,
                        'discount' => $l->discount_amount,
                        'tax_rate' => $l->tax_rate_basis_points,
                        'total' => $l->line_total,
                        'unit_cost' => $showCost ? $l->unit_cost : null,
                        'restock' => $l->restock,
                        'creditable' => Quantity::of($l->quantity)->sub($creditedQuantity)->format(),
                        'creditable_raw' => Quantity::of($l->quantity)->sub($creditedQuantity)->value,
                    ];
                })->values(),
                'payments' => $document->payments->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'direction' => $p->direction,
                    'method' => $p->method,
                    'amount' => $p->amount,
                    'reference' => $p->reference,
                    'note' => $p->note,
                    'paid_at' => $p->paid_at->toIso8601String(),
                    'author' => $p->author->name,
                ])->values(),
            ],
            'sessions' => $isInvoice ? self::openSessions($document->warehouse_id) : [],
            'fromPos' => $request->boolean('receipt') && $document->channel === 'pos',
            'can' => [
                'edit' => $document->isDraft() && Gate::allows('sale.update_draft'),
                'validate' => $document->isDraft() && Gate::allows('sale.validate'),
                'cancel' => ($document->isDraft() || ($document->status === SalesDocument::VALIDATED && $document->type !== SalesDocument::INVOICE && $document->type !== SalesDocument::CREDIT_NOTE)) && Gate::allows('sale.cancel'),
                'convert' => $document->status === SalesDocument::VALIDATED && in_array($document->type, [SalesDocument::QUOTE, SalesDocument::ORDER], true) && Gate::allows('sale.create'),
                'pay' => $isInvoice && $document->balanceDue() > 0 && Gate::allows('cash.receive'),
                'refund' => $isInvoice && $document->balanceDue() < 0 && Gate::allows('cash.refund'),
                'credit' => $isInvoice && Gate::allows('sale.cancel'),
                'negative_stock' => Gate::allows('sale.sell_negative_stock'),
                'share' => $document->number !== null && ! $document->isDraft() && $document->status !== SalesDocument::CANCELLED,
            ],
        ]);
    }

    public function validateDocument(Request $request, SalesDocument $document, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.validate');
        $data = $request->validate(['negative_stock_reason' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $document = $service->validate($document, $user, $data['negative_stock_reason'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.validated', ['document' => __("sales.types.{$document->type}"), 'number' => $document->number])]);

        return to_route('sales.show', $document);
    }

    public function convert(Request $request, SalesDocument $document, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.create');
        $target = (string) $request->validate(['type' => ['required', Rule::in([SalesDocument::ORDER, SalesDocument::INVOICE])]])['type'];

        /** @var User $user */
        $user = $request->user();
        $created = $service->convert($document, $target, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.converted', ['number' => $document->number])]);

        return to_route('sales.show', $created);
    }

    public function cancel(Request $request, SalesDocument $document, SalesDocumentService $service): RedirectResponse
    {
        Gate::authorize('sale.cancel');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $service->cancel($document, $data['reason'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.cancelled')]);

        return to_route('sales.show', $document);
    }

    public function pay(Request $request, SalesDocument $document, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('cash.receive');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
            'tenders' => ['required', 'array', 'min:1', 'max:6'],
            'tenders.*.method' => ['required', Rule::in(Payment::METHODS)],
            'tenders.*.amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $payments->receive($document, $this->tenders($data['tenders']), $data['cash_session_id'] ?? null, $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.payment')]);

        return to_route('sales.show', $document);
    }

    public function refund(Request $request, SalesDocument $document, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('cash.refund');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $payments->refund($document, (int) $data['amount'], (string) $data['method'], $data['cash_session_id'] ?? null, (string) $data['reason'], $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.refund')]);

        return to_route('sales.show', $document);
    }

    public function creditNote(Request $request, SalesDocument $document, CreditNoteService $credits): RedirectResponse
    {
        Gate::authorize('sale.cancel');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.restock' => ['boolean'],
        ], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $lines = array_values(array_map(fn (array $l) => ['line_id' => (string) $l['line_id'], 'quantity' => (string) $l['quantity'], 'restock' => (bool) ($l['restock'] ?? false)], $data['lines']));
        $credit = $credits->create($document, $lines, (string) $data['reason'], $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.credit_note', ['number' => $credit->number])]);

        return to_route('sales.show', $credit);
    }

    /**
     * PDF A4 (devis, commande, facture, avoir) ou ticket 80 mm (?format=receipt).
     */
    public function pdf(Request $request, SalesDocument $document, StockAccess $access, TenantContext $context, SalesDocumentPdf $renderer): HttpResponse
    {
        Gate::authorize('sale.view');
        $this->authorizeWarehouse($document, $access);
        // Seuls les documents numérotés s'impriment : un brouillon n'a pas de valeur.
        abort_if($document->isDraft() || $document->number === null, 404);

        $format = $request->query('format') === SalesDocumentPdf::FORMAT_RECEIPT ? SalesDocumentPdf::FORMAT_RECEIPT : SalesDocumentPdf::FORMAT_A4;

        return $renderer->render($document, $context->tenant(), $format)->stream($renderer->filename($document, $format));
    }

    /**
     * Recherche de clients pour les écrans de vente (nom, code, téléphone).
     */
    public function customers(Request $request, SalesDocumentService $service): JsonResponse
    {
        Gate::authorize('sale.create');
        $term = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        $customers = Customer::query()->whereNull('archived_at')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('diaba_search_text(name) LIKE diaba_search_text(?)', [$like])
                ->orWhereRaw('upper(code) LIKE upper(?)', [$like])
                ->orWhere('phone', 'like', $like)))
            ->orderBy('name')->limit(10)
            ->get(['id', 'code', 'name', 'phone', 'credit_limit'])
            ->map(fn (Customer $c) => [
                ...$c->only(['id', 'code', 'name', 'phone', 'credit_limit']),
                'outstanding' => $service->outstanding($c->id),
            ]);

        return response()->json(['customers' => $customers]);
    }

    /**
     * Sessions de caisse ouvertes du dépôt, pour encaisser.
     *
     * @return list<array{id: string, register: string, opened_by: string}>
     */
    public static function openSessions(string $warehouseId): array
    {
        return array_values(CashSession::query()->with(['register:id,name,warehouse_id', 'opener:id,name'])
            ->where('status', CashSession::OPEN)
            ->whereHas('register', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->map(fn (CashSession $s) => ['id' => $s->id, 'register' => $s->register->name, 'opened_by' => $s->opener->name])
            ->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $tenders
     * @return list<array{method: string, amount: int, reference: string|null}>
     */
    public static function tenders(array $tenders): array
    {
        return array_values(array_map(fn (array $t) => [
            'method' => (string) $t['method'],
            'amount' => (int) $t['amount'],
            'reference' => isset($t['reference']) ? (string) $t['reference'] : null,
        ], $tenders));
    }

    /**
     * @return array{type: string, warehouse_id: string, customer_id: string|null, valid_until: string|null, notes: string|null, version: int|null, lines: list<array{product_id: string, quantity: string, unit_price: int|null, discount: int}>}
     */
    private function validateDraft(Request $request, ?string $type = null): array
    {
        $data = $request->validate([
            'type' => [$type === null ? 'required' : 'nullable', Rule::in(self::DRAFT_TYPES)],
            'warehouse_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], attributes: ['lines' => 'articles', 'valid_until' => 'date de validité']);

        return [
            'type' => $type ?? (string) $data['type'],
            'warehouse_id' => (string) $data['warehouse_id'],
            'customer_id' => isset($data['customer_id']) ? (string) $data['customer_id'] : null,
            'valid_until' => isset($data['valid_until']) ? (string) $data['valid_until'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'lines' => self::lines($data['lines']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{product_id: string, quantity: string, unit_price: int|null, discount: int}>
     */
    public static function lines(array $lines): array
    {
        return array_values(array_map(fn (array $l) => [
            'product_id' => (string) $l['product_id'],
            'quantity' => (string) $l['quantity'],
            'unit_price' => isset($l['unit_price']) ? (int) $l['unit_price'] : null,
            'discount' => (int) ($l['discount'] ?? 0),
        ], $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(StockAccess $access): array
    {
        return [
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'can' => [
                'price_override' => Gate::allows('catalog.price_override'),
                'discount' => Gate::allows('sale.discount_standard') || Gate::allows('sale.discount_exception'),
            ],
        ];
    }

    private function authorizeWarehouse(SalesDocument $document, StockAccess $access): void
    {
        abort_unless(in_array($document->warehouse_id, $access->accessibleIds(), true), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SalesDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'number' => $document->number,
            'status' => $document->status,
            'channel' => $document->channel,
            'payment_status' => $document->payment_status,
            'warehouse' => $document->warehouse->only(['id', 'code', 'name']),
            'customer' => $document->customer?->only(['id', 'code', 'name']) ?? ($document->customer_snapshot !== null ? ['id' => null, 'code' => $document->customer_snapshot['code'] ?? null, 'name' => $document->customer_snapshot['name'] ?? null] : null),
            'total' => $document->total,
            'balance' => $document->balanceDue(),
            'created_at' => $document->created_at?->toIso8601String(),
            'validated_at' => $document->validated_at?->toIso8601String(),
        ];
    }
}
