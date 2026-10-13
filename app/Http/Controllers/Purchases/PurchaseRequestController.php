<?php

namespace App\Http\Controllers\Purchases;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Purchases\PurchaseRequestService;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demandes d'achat. Chacun voit les siennes ; les achats et les approbateurs voient
 * toutes celles des dépôts auxquels ils ont accès. Seul l'auteur modifie et soumet
 * son brouillon ; l'approbation exige un droit dédié.
 */
class PurchaseRequestController extends Controller
{
    public function index(Request $request, StockAccess $access): Response
    {
        Gate::authorize('purchase_request.view');
        /** @var User $user */
        $user = $request->user();
        $status = (string) $request->query('status', '');

        $requests = PurchaseRequest::query()->with(['warehouse:id,code,name', 'author:id,name'])->withCount('lines')
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->when(! $this->seesAll(), fn ($q) => $q->where('created_by', $user->id))
            ->when($status === 'pending', fn ($q) => $q->where('status', PurchaseRequest::SUBMITTED))
            ->when(in_array($status, ['draft', 'approved', 'rejected', 'ordered', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->latest()->paginate(25)->withQueryString()
            ->through(fn (PurchaseRequest $r) => $this->summary($r));

        return Inertia::render('purchases/Requests', [
            'requests' => $requests,
            'filters' => ['status' => $status],
            'can' => ['create' => Gate::allows('purchase_request.create'), 'approve' => Gate::allows('purchase_request.approve')],
        ]);
    }

    public function create(StockAccess $access): Response
    {
        Gate::authorize('purchase_request.create');

        return Inertia::render('purchases/RequestForm', ['purchaseRequest' => null, ...$this->formData($access)]);
    }

    public function store(Request $request, PurchaseRequestService $service): RedirectResponse
    {
        Gate::authorize('purchase_request.create');

        /** @var User $user */
        $user = $request->user();
        $saved = $service->saveDraft(null, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.saved')]);

        return to_route('purchases.requests.show', $saved);
    }

    public function edit(Request $request, PurchaseRequest $purchaseRequest, StockAccess $access): Response|RedirectResponse
    {
        $this->authorizeView($request, $purchaseRequest, $access);
        abort_unless($this->isAuthor($request, $purchaseRequest), 403);

        if ($purchaseRequest->status !== PurchaseRequest::DRAFT) {
            return to_route('purchases.requests.show', $purchaseRequest);
        }

        $purchaseRequest->load('lines.product.unit');

        return Inertia::render('purchases/RequestForm', [
            'purchaseRequest' => [
                'id' => $purchaseRequest->id,
                'version' => $purchaseRequest->version,
                'warehouse_id' => $purchaseRequest->warehouse_id,
                'needed_on' => $purchaseRequest->needed_on?->toDateString(),
                'notes' => $purchaseRequest->notes,
                'lines' => $purchaseRequest->lines->map(fn (PurchaseRequestLine $l) => [
                    'product' => ['id' => $l->product_id, 'sku' => $l->sku, 'name' => $l->label, 'unit' => $l->unit_code, 'decimals' => $l->product->unit->decimals_allowed, 'standard_cost' => null],
                    'quantity' => Quantity::of($l->quantity)->format(),
                    'note' => $l->note,
                ])->values(),
            ],
            ...$this->formData($access),
        ]);
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        $this->authorizeView($request, $purchaseRequest, $access);
        abort_unless($this->isAuthor($request, $purchaseRequest), 403);

        /** @var User $user */
        $user = $request->user();
        $service->saveDraft($purchaseRequest, $this->validated($request), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.saved')]);

        return to_route('purchases.requests.show', $purchaseRequest);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest, StockAccess $access): Response
    {
        $this->authorizeView($request, $purchaseRequest, $access);
        $purchaseRequest->load(['lines', 'warehouse:id,code,name', 'author:id,name', 'order:id,number']);
        $status = $purchaseRequest->status;
        $author = $this->isAuthor($request, $purchaseRequest);

        return Inertia::render('purchases/RequestShow', [
            'purchaseRequest' => [
                ...$this->summary($purchaseRequest),
                'needed_on' => $purchaseRequest->needed_on?->toDateString(),
                'notes' => $purchaseRequest->notes,
                'decision_reason' => $purchaseRequest->decision_reason,
                'cancel_reason' => $purchaseRequest->cancel_reason,
                'order' => $purchaseRequest->order?->only(['id', 'number']),
                'lines' => $purchaseRequest->lines->map(fn (PurchaseRequestLine $l) => [
                    'id' => $l->id, 'sku' => $l->sku, 'label' => $l->label, 'unit' => $l->unit_code,
                    'quantity' => Quantity::of($l->quantity)->format(), 'note' => $l->note,
                ])->values(),
            ],
            'suppliers' => $status === PurchaseRequest::APPROVED && Gate::allows('purchase.create')
                ? Supplier::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'code', 'name'])
                : [],
            'can' => [
                'edit' => $status === PurchaseRequest::DRAFT && $author,
                'submit' => $status === PurchaseRequest::DRAFT && $author,
                'decide' => $status === PurchaseRequest::SUBMITTED && Gate::allows('purchase_request.approve'),
                'convert' => $status === PurchaseRequest::APPROVED && Gate::allows('purchase.create'),
                'cancel' => in_array($status, [PurchaseRequest::DRAFT, PurchaseRequest::SUBMITTED, PurchaseRequest::APPROVED], true)
                    && ($author || Gate::allows('purchase_request.approve')),
            ],
        ]);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        $this->authorizeView($request, $purchaseRequest, $access);
        abort_unless($this->isAuthor($request, $purchaseRequest), 403);

        /** @var User $user */
        $user = $request->user();
        $saved = $service->submit($purchaseRequest, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.submitted', ['number' => $saved->number])]);

        return to_route('purchases.requests.show', $saved);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        Gate::authorize('purchase_request.approve');
        $this->authorizeView($request, $purchaseRequest, $access);

        /** @var User $user */
        $user = $request->user();
        $service->approve($purchaseRequest, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.approved')]);

        return to_route('purchases.requests.show', $purchaseRequest);
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        Gate::authorize('purchase_request.approve');
        $this->authorizeView($request, $purchaseRequest, $access);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $service->reject($purchaseRequest, (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.rejected')]);

        return to_route('purchases.requests.show', $purchaseRequest);
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        $this->authorizeView($request, $purchaseRequest, $access);
        abort_unless($this->isAuthor($request, $purchaseRequest) || Gate::allows('purchase_request.approve'), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $service->cancel($purchaseRequest, $data['reason'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.cancelled')]);

        return to_route('purchases.requests.show', $purchaseRequest);
    }

    public function convert(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service, StockAccess $access): RedirectResponse
    {
        Gate::authorize('purchase.create');
        $this->authorizeView($request, $purchaseRequest, $access);
        $data = $request->validate(['supplier_id' => ['required', 'uuid']], attributes: ['supplier_id' => 'fournisseur']);

        /** @var User $user */
        $user = $request->user();
        $order = $service->convertToOrder($purchaseRequest, (string) $data['supplier_id'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.requests.converted')]);

        return to_route('purchases.orders.edit', $order);
    }

    private function seesAll(): bool
    {
        return Gate::allows('purchase.view') || Gate::allows('purchase_request.approve');
    }

    private function isAuthor(Request $request, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->created_by === $request->user()?->id;
    }

    /**
     * Dépôt accessible, et demande visible par son auteur ou par les achats/approbateurs.
     */
    private function authorizeView(Request $request, PurchaseRequest $purchaseRequest, StockAccess $access): void
    {
        Gate::authorize('purchase_request.view');
        abort_unless(in_array($purchaseRequest->warehouse_id, $access->accessibleIds(), true), 404);
        abort_unless($this->seesAll() || $this->isAuthor($request, $purchaseRequest), 404);
    }

    /**
     * @return array{warehouse_id: string, needed_on: string|null, notes: string|null, version: int|null, lines: list<array{product_id: string, quantity: string, note: string|null}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'needed_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ], attributes: ['lines' => 'articles']);

        return [
            'warehouse_id' => (string) $data['warehouse_id'],
            'needed_on' => isset($data['needed_on']) ? (string) $data['needed_on'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'lines' => array_values(array_map(fn (array $l) => [
                'product_id' => (string) $l['product_id'], 'quantity' => (string) $l['quantity'], 'note' => isset($l['note']) ? (string) $l['note'] : null,
            ], $data['lines'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(StockAccess $access): array
    {
        return ['warehouses' => $access->accessible()->map->only(['id', 'code', 'name'])->values()];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PurchaseRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'warehouse' => $r->warehouse->only(['id', 'code', 'name']),
            'author' => $r->author->name,
            'lines_count' => $r->lines_count ?? $r->lines->count(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
