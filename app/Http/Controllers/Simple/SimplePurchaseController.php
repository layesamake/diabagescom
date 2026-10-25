<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Purchases\GoodsReceiptService;
use App\Domain\Purchases\PurchaseOrderService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Mode simple — lot 6 : commandes fournisseurs à recevoir.
 *
 * La réception elle-même reste portée par GoodsReceiptService, commun au mode
 * expert. Cet écran ne montre que les commandes des dépôts accessibles.
 */
class SimplePurchaseController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(StockAccess $access): Response
    {
        Gate::authorize('purchase.view');

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->whereIn('status', [
                PurchaseOrder::CONFIRMED,
                PurchaseOrder::PARTIALLY_RECEIVED,
                PurchaseOrder::RECEIVED,
            ])
            ->latest()
            ->get()
            ->map(fn (PurchaseOrder $order): array => $this->summary($order));

        return Inertia::render('simple/Achats', [
            'open_orders' => $orders
                ->whereIn('status', [
                    PurchaseOrder::CONFIRMED,
                    PurchaseOrder::PARTIALLY_RECEIVED,
                ])
                ->values(),
            'received_orders' => $orders
                ->where('status', PurchaseOrder::RECEIVED)
                ->values(),
            'can' => ['order' => $this->canOrder()],
            'audio_code' => 'ACH-01',
        ]);
    }

    /**
     * « Commander » crée la commande et la confirme (elle est alors « en route ») :
     * il faut donc les deux droits du mode expert, que seul l'acheteur reçoit.
     */
    private function canOrder(): bool
    {
        return Gate::allows('purchase.update_draft') && Gate::allows('purchase.validate');
    }

    /** Formulaire « Commander » : le fournisseur, puis les articles et leurs quantités. */
    public function create(StockAccess $access, TenantContext $context): Response
    {
        abort_unless($this->canOrder(), 403);

        $warehouse = $access->accessible()->firstWhere('id', $context->warehouse()?->id)
            ?? $access->accessible()->first();

        $products = Product::query()
            ->with('unit:id,code,decimals_allowed')
            ->whereNull('archived_at')
            ->where('active', true)
            ->where('type', '!=', 'service')
            ->orderBy('name')
            ->limit(200)
            ->get();
        $balances = $warehouse === null ? null : DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $products->pluck('id'));
        $onHand = $balances?->pluck('quantity_on_hand', 'product_id') ?? collect();
        $averages = $balances?->pluck('average_cost', 'product_id') ?? collect();

        return Inertia::render('simple/AchatCommande', [
            'warehouse' => $warehouse?->only(['id', 'code', 'name']),
            'suppliers' => Supplier::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'products' => $products->map(function (Product $product) use ($onHand, $averages): array {
                $average = (int) ($averages[$product->id] ?? 0);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'unit' => $product->unit->code,
                    'image_url' => CatalogImages::url('products.image', $product->image_path, ['product' => $product->id], $product->version),
                    'stock' => Quantity::of((string) ($onHand[$product->id] ?? '0'))->format(),
                    // Prix d'achat proposé : coût moyen du dépôt, sinon coût standard (modifiable).
                    'cost' => $average > 0 ? $average : (int) $product->standard_cost,
                ];
            })->values(),
        ]);
    }

    /**
     * Crée puis confirme la commande, avec les mêmes règles que le mode expert
     * (numéro, montants figés, droits, abonnement). La clé évite qu'un double
     * appui ne crée deux commandes.
     */
    public function store(Request $request, PurchaseOrderService $orders): RedirectResponse
    {
        abort_unless($this->canOrder(), 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct'],
            // Des pièces entières : « 1 », « 30 », jamais « 0 » ni « 1,5 ».
            'lines.*.quantity' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,8}\z/'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], attributes: ['supplier_id' => 'fournisseur', 'lines' => 'articles', 'lines.*.quantity' => 'quantité', 'lines.*.unit_cost' => 'prix d’achat']);

        /** @var User $user */
        $user = $request->user();
        $key = "simple-purchase-order:{$user->id}:{$data['idempotency_key']}";

        // Déjà traité (double appui, renvoi) : on revient à la liste sans créer de doublon.
        if (! Cache::add($key, true, 3600)) {
            return to_route('simple.purchases');
        }

        try {
            $order = DB::transaction(function () use ($orders, $data, $user): PurchaseOrder {
                $draft = $orders->saveDraft(null, [
                    'warehouse_id' => (string) $data['warehouse_id'],
                    'supplier_id' => (string) $data['supplier_id'],
                    'lines' => array_values(array_map(fn (array $line): array => [
                        'product_id' => (string) $line['product_id'],
                        'quantity' => (string) $line['quantity'],
                        'unit_cost' => (int) $line['unit_cost'],
                    ], $data['lines'])),
                ], $user);

                return $orders->confirm($draft, $user);
            });
        } catch (Throwable $e) {
            // Un échec ne doit pas bloquer le renvoi avec la même clé.
            Cache::forget($key);

            throw $e;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('purchases.saved.order_confirmed', ['number' => $order->number])]);

        return to_route('simple.purchases');
    }

    public function show(PurchaseOrder $order, StockAccess $access): Response
    {
        Gate::authorize('purchase.view');
        abort_unless(in_array($order->warehouse_id, $access->accessibleIds(), true), 404);

        $order->load(['supplier:id,name', 'lines.product.unit']);
        $receivable = in_array($order->status, [
            PurchaseOrder::CONFIRMED,
            PurchaseOrder::PARTIALLY_RECEIVED,
        ], true);

        return Inertia::render('simple/Achat', [
            'order' => [
                ...$this->summary($order),
                'supplier' => $order->supplier->only(['id', 'name']),
                'lines' => $order->lines->map(function ($line): array {
                    $remaining = Quantity::of($line->quantity)
                        ->sub(Quantity::of($line->quantity_received));

                    return [
                        'id' => $line->id,
                        'product_id' => $line->product_id,
                        'name' => $line->label,
                        'unit' => $line->unit_code,
                        'quantity' => Quantity::of($line->quantity)->format(),
                        'received' => Quantity::of($line->quantity_received)->format(),
                        'remaining' => $remaining->format(),
                    ];
                })->values(),
            ],
            'can' => ['receive' => $receivable && Gate::allows('purchase.receive')],
            'audio_code' => 'ACH-05',
        ]);
    }

    public function receive(
        Request $request,
        PurchaseOrder $order,
        StockAccess $access,
        GoodsReceiptService $receipts,
    ): RedirectResponse {
        Gate::authorize('purchase.receive');
        abort_unless(in_array($order->warehouse_id, $access->accessibleIds(), true), 404);

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'quantities' => ['required', 'array', 'max:200'],
            'quantities.*' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var array<string, string> $quantities */
        $quantities = array_map(
            fn ($quantity): string => (string) $quantity,
            $data['quantities'],
        );
        $receipts->receiveOrder(
            $order,
            $quantities,
            null,
            null,
            $user,
            (string) $data['idempotency_key'],
        );
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Stock mis à jour.',
        ]);

        return to_route('simple.purchases.show', $order);
    }

    /**
     * @return array{id: string, number: string|null, status: string, supplier: string, total: int, expected_on: string|null}
     */
    private function summary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'supplier' => $order->supplier->name,
            'total' => $order->total,
            'expected_on' => $order->expected_on?->toDateString(),
        ];
    }
}
