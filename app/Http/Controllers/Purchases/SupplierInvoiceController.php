<?php

namespace App\Http\Controllers\Purchases;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Purchases\SupplierInvoiceService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierInvoiceController extends Controller
{
    public function index(Request $request, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        $ids = $access->accessibleIds();
        $status = (string) $request->query('status', '');

        $invoices = SupplierInvoice::query()->with(['supplier:id,code,name', 'warehouse:id,code,name'])
            ->whereIn('warehouse_id', $ids)
            ->when($status === 'unpaid', fn ($q) => $q->where('status', SupplierInvoice::VALIDATED)->whereIn('payment_status', ['unpaid', 'partial']))
            ->when(in_array($status, ['draft', 'validated', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->latest()->paginate(25)->withQueryString()
            ->through(fn (SupplierInvoice $i) => $this->summary($i));

        // Dettes par fournisseur : factures validées non soldées.
        $debts = SupplierInvoice::query()->join('suppliers', 'suppliers.id', '=', 'supplier_invoices.supplier_id')
            ->whereIn('supplier_invoices.warehouse_id', $ids)
            ->where('supplier_invoices.status', SupplierInvoice::VALIDATED)
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.code')
            ->havingRaw('SUM(GREATEST(total - amount_paid - amount_credited, 0)) > 0')
            ->orderByDesc(DB::raw('SUM(GREATEST(total - amount_paid - amount_credited, 0))'))
            ->limit(10)
            ->get(['suppliers.id', 'suppliers.code', 'suppliers.name', DB::raw('SUM(GREATEST(total - amount_paid - amount_credited, 0)) AS due'),
                DB::raw('SUM(CASE WHEN due_date < CURRENT_DATE THEN GREATEST(total - amount_paid - amount_credited, 0) ELSE 0 END) AS overdue')])
            ->map(fn ($row) => ['id' => $row->getAttribute('id'), 'code' => $row->getAttribute('code'), 'name' => $row->getAttribute('name'),
                'due' => (int) $row->getAttribute('due'), 'overdue' => (int) $row->getAttribute('overdue')]);

        return Inertia::render('purchases/Invoices', [
            'invoices' => $invoices,
            'debts' => $debts,
            'filters' => ['status' => $status],
            'can' => ['create' => Gate::allows('purchase.create')],
        ]);
    }

    public function create(Request $request, StockAccess $access, SupplierInvoiceService $service): Response
    {
        Gate::authorize('purchase.create');
        $prefill = null;

        if ($request->filled('receipt')) {
            $receipt = GoodsReceipt::query()->findOrFail((string) $request->query('receipt'));
            abort_unless(in_array($receipt->warehouse_id, $access->accessibleIds(), true), 404);
            $prefill = ['supplier_id' => $receipt->supplier_id, 'warehouse_id' => $receipt->warehouse_id, 'goods_receipt_id' => $receipt->id,
                'purchase_order_id' => $receipt->purchase_order_id, 'source' => $receipt->number, 'lines' => $this->formatLines($service->linesFromReceipt($receipt))];
        } elseif ($request->filled('order')) {
            $order = PurchaseOrder::query()->with('lines')->findOrFail((string) $request->query('order'));
            abort_unless(in_array($order->warehouse_id, $access->accessibleIds(), true), 404);
            $prefill = ['supplier_id' => $order->supplier_id, 'warehouse_id' => $order->warehouse_id, 'goods_receipt_id' => null,
                'purchase_order_id' => $order->id, 'source' => $order->number,
                'lines' => $this->formatLines($order->lines->map(fn (PurchaseOrderLine $l) => ['product_id' => $l->product_id, 'label' => $l->label,
                    'quantity' => $l->quantity, 'unit_cost' => $l->unit_cost, 'tax_rate_basis_points' => $l->tax_rate_basis_points])->all())];
        }

        return Inertia::render('purchases/InvoiceForm', ['invoice' => null, 'prefill' => $prefill, ...$this->formData($access)]);
    }

    public function store(Request $request, SupplierInvoiceService $service): RedirectResponse
    {
        Gate::authorize('purchase.create');

        /** @var User $user */
        $user = $request->user();
        $invoice = $service->saveDraft(null, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.invoice_draft')]);

        return to_route('purchases.invoices.show', $invoice);
    }

    public function edit(SupplierInvoice $invoice, StockAccess $access): Response|RedirectResponse
    {
        Gate::authorize('purchase.update_draft');
        $this->authorizeWarehouse($invoice, $access);

        if ($invoice->status !== SupplierInvoice::DRAFT) {
            return to_route('purchases.invoices.show', $invoice);
        }

        $invoice->load('lines');

        return Inertia::render('purchases/InvoiceForm', [
            'invoice' => [
                'id' => $invoice->id,
                'version' => $invoice->version,
                'supplier_id' => $invoice->supplier_id,
                'warehouse_id' => $invoice->warehouse_id,
                'supplier_reference' => $invoice->supplier_reference,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'goods_receipt_id' => $invoice->goods_receipt_id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'notes' => $invoice->notes,
                'lines' => $this->formatLines($invoice->lines->map(fn (SupplierInvoiceLine $l) => $l->only(['product_id', 'label', 'quantity', 'unit_cost', 'tax_rate_basis_points']))->all()),
            ],
            'prefill' => null,
            ...$this->formData($access),
        ]);
    }

    public function update(Request $request, SupplierInvoice $invoice, SupplierInvoiceService $service): RedirectResponse
    {
        Gate::authorize('purchase.update_draft');

        /** @var User $user */
        $user = $request->user();
        $service->saveDraft($invoice, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.invoice_draft')]);

        return to_route('purchases.invoices.show', $invoice);
    }

    public function show(SupplierInvoice $invoice, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        $this->authorizeWarehouse($invoice, $access);
        $invoice->load(['lines', 'supplier:id,code,name,phone', 'warehouse:id,code,name', 'purchaseOrder:id,number', 'goodsReceipt:id,number',
            'payments' => fn ($q) => $q->with('author:id,name')->orderBy('paid_at'), 'returns']);
        $validated = $invoice->status === SupplierInvoice::VALIDATED;

        return Inertia::render('purchases/InvoiceShow', [
            'invoice' => [
                ...$this->summary($invoice),
                'issue_date' => $invoice->issue_date?->toDateString(),
                'notes' => $invoice->notes,
                'total_excl_tax' => $invoice->total_excl_tax,
                'total_tax' => $invoice->total_tax,
                'amount_paid' => $invoice->amount_paid,
                'amount_credited' => $invoice->amount_credited,
                'order' => $invoice->purchaseOrder?->only(['id', 'number']),
                'receipt' => $invoice->goodsReceipt?->only(['id', 'number']),
                'lines' => $invoice->lines->map(fn (SupplierInvoiceLine $l) => [
                    'id' => $l->id,
                    'label' => $l->label,
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'unit_cost' => $l->unit_cost,
                    'tax_rate' => $l->tax_rate_basis_points,
                    'total' => $l->line_total,
                ])->values(),
                'payments' => $invoice->payments->map(fn (Payment $p) => [
                    'id' => $p->id, 'number' => $p->number, 'method' => $p->method, 'amount' => $p->amount,
                    'reference' => $p->reference, 'paid_at' => $p->paid_at->toIso8601String(), 'author' => $p->author->name,
                ])->values(),
                'returns' => $invoice->returns->map->only(['id', 'number', 'total', 'reason'])->values(),
            ],
            'sessions' => $validated ? SalesDocumentController::openSessions($invoice->warehouse_id) : [],
            'can' => [
                'edit' => $invoice->status === SupplierInvoice::DRAFT && Gate::allows('purchase.update_draft'),
                'validate' => $invoice->status === SupplierInvoice::DRAFT && Gate::allows('purchase.validate'),
                'cancel' => $invoice->status === SupplierInvoice::DRAFT && Gate::allows('purchase.cancel'),
                'pay' => $validated && $invoice->balanceDue() > 0 && Gate::allows('purchase.validate'),
            ],
        ]);
    }

    public function validateInvoice(Request $request, SupplierInvoice $invoice, SupplierInvoiceService $service): RedirectResponse
    {
        Gate::authorize('purchase.validate');

        /** @var User $user */
        $user = $request->user();
        $invoice = $service->validate($invoice, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.invoice_validated', ['number' => $invoice->number])]);

        return to_route('purchases.invoices.show', $invoice);
    }

    public function cancel(Request $request, SupplierInvoice $invoice, SupplierInvoiceService $service): RedirectResponse
    {
        Gate::authorize('purchase.cancel');

        /** @var User $user */
        $user = $request->user();
        $service->cancel($invoice, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.invoice_cancelled')]);

        return to_route('purchases.invoices.show', $invoice);
    }

    public function pay(Request $request, SupplierInvoice $invoice, SupplierPaymentService $payments): RedirectResponse
    {
        Gate::authorize('purchase.validate');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'cash_session_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], attributes: ['amount' => 'montant']);

        if ($data['method'] === 'cash') {
            Gate::authorize('cash.expense');
        }

        /** @var User $user */
        $user = $request->user();
        $payment = $payments->pay($invoice, (string) $data['method'], (int) $data['amount'], $data['cash_session_id'] ?? null, $data['reference'] ?? null, $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.payment', ['number' => $payment->number])]);

        return to_route('purchases.invoices.show', $invoice);
    }

    /**
     * @return array{supplier_id: string, warehouse_id: string, supplier_reference: string|null, issue_date: string|null, due_date: string|null, purchase_order_id: string|null, goods_receipt_id: string|null, notes: string|null, version: int|null, lines: list<array{product_id: string|null, label: string, quantity: string, unit_cost: int, tax_rate_basis_points: int}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'supplier_reference' => ['nullable', 'string', 'max:64'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'goods_receipt_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.label' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.tax_rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
        ], attributes: ['supplier_id' => 'fournisseur', 'supplier_reference' => 'n° de facture fournisseur', 'lines' => 'lignes']);

        return [
            'supplier_id' => (string) $data['supplier_id'],
            'warehouse_id' => (string) $data['warehouse_id'],
            'supplier_reference' => isset($data['supplier_reference']) ? (string) $data['supplier_reference'] : null,
            'issue_date' => isset($data['issue_date']) ? (string) $data['issue_date'] : null,
            'due_date' => isset($data['due_date']) ? (string) $data['due_date'] : null,
            'purchase_order_id' => isset($data['purchase_order_id']) ? (string) $data['purchase_order_id'] : null,
            'goods_receipt_id' => isset($data['goods_receipt_id']) ? (string) $data['goods_receipt_id'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'lines' => array_values(array_map(fn (array $l) => [
                'product_id' => isset($l['product_id']) ? (string) $l['product_id'] : null,
                'label' => (string) $l['label'],
                'quantity' => (string) $l['quantity'],
                'unit_cost' => (int) $l['unit_cost'],
                'tax_rate_basis_points' => (int) $l['tax_rate_basis_points'],
            ], $data['lines'])),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function formatLines(array $lines): array
    {
        return array_values(array_map(fn (array $l) => [...$l, 'quantity' => Quantity::of((string) $l['quantity'])->format()], $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(StockAccess $access): array
    {
        return [
            'warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values(),
            'suppliers' => Supplier::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'code', 'name']),
            'taxRates' => TaxRate::query()->where('active', true)->orderByDesc('is_default')->get(['id', 'name', 'rate_basis_points']),
        ];
    }

    private function authorizeWarehouse(SupplierInvoice $invoice, StockAccess $access): void
    {
        abort_unless(in_array($invoice->warehouse_id, $access->accessibleIds(), true), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SupplierInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'supplier_reference' => $invoice->supplier_reference,
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'supplier' => $invoice->supplier->only(['id', 'code', 'name']),
            'warehouse' => $invoice->warehouse->only(['id', 'code', 'name']),
            'total' => $invoice->total,
            'balance' => $invoice->balanceDue(),
            'due_date' => $invoice->due_date?->toDateString(),
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }
}
