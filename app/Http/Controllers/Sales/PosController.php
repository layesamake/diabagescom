<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\PointOfSale;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vente comptoir tactile : scan/recherche → panier → paiement → reçu.
 */
class PosController extends Controller
{
    public function create(Request $request, StockAccess $access, TenantContext $context): Response
    {
        Gate::authorize('sale.create');
        Gate::authorize('sale.validate');

        $warehouses = $access->accessible();
        $requested = (string) $request->query('warehouse', '');
        $warehouse = $warehouses->firstWhere('id', $requested)
            ?? $warehouses->firstWhere('id', $context->warehouse()?->id)
            ?? $warehouses->first();

        return Inertia::render('sales/Pos', [
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
            'warehouse' => $warehouse?->only(['id', 'code', 'name']),
            'sessions' => $warehouse !== null ? SalesDocumentController::openSessions($warehouse->id) : [],
            // Caisses fermées du dépôt, pour ouvrir directement depuis l'écran de vente.
            'registers' => $warehouse !== null ? CashRegister::query()->where('warehouse_id', $warehouse->id)->where('active', true)
                ->whereDoesntHave('openSession')->orderBy('name')->get(['id', 'name']) : [],
            'methods' => Payment::METHODS,
            'frequent' => $warehouse !== null ? $this->frequentProducts($warehouse->id) : [],
            'can' => [
                'price_override' => Gate::allows('catalog.price_override'),
                'discount' => Gate::allows('sale.discount_standard') || Gate::allows('sale.discount_exception'),
                'negative_stock' => Gate::allows('sale.sell_negative_stock'),
                'open_cash' => Gate::allows('cash.open_close'),
            ],
        ]);
    }

    public function store(Request $request, PointOfSale $pos): RedirectResponse
    {
        Gate::authorize('sale.create');
        Gate::authorize('sale.validate');

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'cash_session_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'negative_stock_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'tenders' => ['present', 'array', 'max:6'],
            'tenders.*.method' => ['required', Rule::in(Payment::METHODS)],
            'tenders.*.amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
        ], attributes: ['cash_session_id' => 'caisse', 'lines' => 'articles']);

        $tenders = SalesDocumentController::tenders($data['tenders']);

        if (array_filter($tenders, fn (array $t) => $t['amount'] > 0) !== []) {
            Gate::authorize('cash.receive');
        }

        /** @var User $user */
        $user = $request->user();
        $sale = $pos->checkout([
            'warehouse_id' => (string) $data['warehouse_id'],
            'customer_id' => isset($data['customer_id']) ? (string) $data['customer_id'] : null,
            'cash_session_id' => (string) $data['cash_session_id'],
            'negative_stock_reason' => isset($data['negative_stock_reason']) ? (string) $data['negative_stock_reason'] : null,
            'lines' => SalesDocumentController::lines($data['lines']),
            'tenders' => $tenders,
        ], $user, (string) $data['idempotency_key']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.sale', ['number' => $sale->number])]);

        return to_route('sales.show', ['document' => $sale, 'receipt' => 1]);
    }

    /**
     * Articles les plus vendus du dépôt sur 60 jours (tuiles d'ajout rapide) ;
     * boutique sans historique : premiers articles par ordre alphabétique.
     *
     * @return list<array<string, mixed>>
     */
    private function frequentProducts(string $warehouseId): array
    {
        $ranked = DB::table('sales_document_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.warehouse_id', $warehouseId)->where('d.type', 'invoice')->where('d.status', 'validated')
            ->where('d.validated_at', '>=', now()->subDays(60))
            ->groupBy('l.product_id')->orderByRaw('count(*) desc')->limit(12)->pluck('l.product_id')->all();

        $products = Product::query()->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereNull('archived_at')->where('active', true)
            ->when($ranked !== [], fn ($q) => $q->whereIn('id', $ranked), fn ($q) => $q->orderBy('name')->limit(12))
            ->get()
            ->sortBy(fn (Product $p) => $ranked === [] ? $p->name : array_search($p->id, $ranked, true))
            ->values();
        $available = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $products->pluck('id'))->pluck('quantity_on_hand', 'product_id');

        return array_values($products->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'type' => $p->type,
            'unit' => $p->unit->code,
            'decimals' => $p->unit->decimals_allowed,
            'standard_cost' => null,
            'sale_price' => $p->sale_price,
            'tax_rate_basis_points' => $p->taxRate->rate_basis_points,
            'available' => $p->type !== 'service' ? Quantity::of((string) ($available[$p->id] ?? '0'))->format() : null,
        ])->all());
    }
}
