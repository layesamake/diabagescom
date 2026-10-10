<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Barcode;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('catalog.view');

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => (string) $request->query('category', ''),
            'status' => in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active',
        ];

        $products = Product::query()
            ->with(['unit:id,code', 'category:id,name', 'barcodes' => fn ($q) => $q->where('is_primary', true)])
            ->search($filters['q'])
            ->when($filters['category'] !== '', fn ($q) => $q->where('category_id', $filters['category']))
            ->when($filters['status'] === 'active', fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['status'] === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $p) => $this->row($p));

        return Inertia::render('catalog/Products', [
            'products' => $products,
            'filters' => $filters,
            'categories' => ProductCategory::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'can' => [
                'create' => Gate::allows('catalog.create'),
                'import' => Gate::allows('catalog.create') && Gate::allows('catalog.update'),
            ],
        ]);
    }

    /**
     * Recherche exacte par code-barres ou SKU, pour le scan (lecteur ou caméra).
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('catalog.view');

        $raw = trim((string) $request->query('code', ''));

        try {
            $code = Barcode::parse($raw)->code;
        } catch (InvalidArgumentException) {
            $code = $raw; // Un SKU n'a pas forcément le format d'un code-barres.
        }

        $product = Product::query()
            ->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereNull('archived_at')
            ->where(fn ($q) => $q
                ->whereHas('barcodes', fn ($b) => $b->whereRaw('upper(code) = upper(?)', [$code]))
                ->orWhereRaw('upper(sku) = upper(?)', [$raw]))
            ->first();

        if ($product === null) {
            return response()->json(['message' => __('catalog.barcode.not_found')], 404);
        }

        return response()->json(['product' => [
            ...$this->row($product),
            'unit_decimals' => $product->unit->decimals_allowed,
            'tax_rate_basis_points' => $product->taxRate->rate_basis_points,
        ]]);
    }

    /**
     * Sélecteur d'articles (lignes de stock, de transfert, d'inventaire) : 10 résultats.
     */
    public function search(Request $request, StockAccess $access): JsonResponse
    {
        Gate::authorize('catalog.view');

        // Le coût d'achat est une donnée sensible : réservé à qui saisit du stock valorisé.
        $showCost = Gate::allows('inventory.adjust');

        // Stock disponible affiché à la vente, seulement pour un dépôt accessible.
        $warehouseId = (string) $request->query('warehouse', '');
        $stock = $warehouseId !== '' && in_array($warehouseId, $access->accessibleIds(), true) ? $warehouseId : null;

        $products = Product::query()
            ->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points'])
            ->whereNull('archived_at')
            ->where('active', true)
            ->when($request->boolean('stocked'), fn ($q) => $q->whereIn('type', ['stockable', 'consumable']))
            ->search((string) $request->query('q', ''))
            ->orderBy('name')
            ->limit(10)
            ->get();

        $available = $stock !== null
            ? DB::table('inventory_balances')->where('warehouse_id', $stock)->whereIn('product_id', $products->pluck('id'))->pluck('quantity_on_hand', 'product_id')
            : collect();

        $products = $products->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'type' => $p->type,
            'unit' => $p->unit->code,
            'decimals' => $p->unit->decimals_allowed,
            'standard_cost' => $showCost ? $p->standard_cost : null,
            'sale_price' => $p->sale_price,
            'tax_rate_basis_points' => $p->taxRate->rate_basis_points,
            'available' => $stock !== null && $p->type !== 'service' ? Quantity::of((string) ($available[$p->id] ?? '0'))->format() : null,
        ]);

        return response()->json(['products' => $products]);
    }

    public function create(): Response
    {
        Gate::authorize('catalog.create');

        return Inertia::render('catalog/ProductForm', $this->formData(null));
    }

    public function store(ProductRequest $request, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.create');

        /** @var User $user */
        $user = $request->user();
        /** @var array{sku: string, name: string, type: string, unit_id: string, sale_price: int} $data */
        $data = $request->validated();
        $product = $service->create($data, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.product.created', ['name' => $product->name])]);

        return to_route('products.edit', $product);
    }

    public function edit(Product $product): Response
    {
        Gate::authorize('catalog.view');

        return Inertia::render('catalog/ProductForm', $this->formData($product));
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        /** @var User $user */
        $user = $request->user();
        /** @var array{sku: string, name: string, type: string, unit_id: string, tax_rate_id: string, sale_price: int, version: int} $data */
        $data = $request->validated();
        $service->update($product, $data, (int) $data['version'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.product.updated')]);

        return to_route('products.edit', $product);
    }

    public function archive(Request $request, Product $product, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.archive');

        $archived = $request->boolean('archived', true);
        $service->setArchived($product, $archived);

        Inertia::flash('toast', ['type' => 'success', 'message' => __($archived ? 'catalog.product.archived' : 'catalog.product.restored')]);

        return back(303);
    }

    public function addBarcode(Request $request, Product $product, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $data = $request->validate([
            'barcode' => ['required_without:generate', 'nullable', 'string', 'max:64'],
            'generate' => ['sometimes', 'boolean'],
            'primary' => ['sometimes', 'boolean'],
        ]);

        $request->boolean('generate')
            ? $service->generateInternalBarcode($product)
            : $service->addBarcode($product, (string) $data['barcode'], primary: $request->boolean('primary'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.product.barcode_added')]);

        return back(303);
    }

    public function removeBarcode(Product $product, ProductBarcode $barcode, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');
        abort_unless($barcode->product_id === $product->id, 404);

        $service->removeBarcode($barcode);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.product.barcode_removed')]);

        return back(303);
    }

    public function uploadImage(Request $request, Product $product, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $request->validate(['image' => CatalogImages::RULES], attributes: ['image' => 'photo']);

        $service->setImage($product, $request->file('image'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.product.image_updated')]);

        return back(303);
    }

    public function removeImage(Product $product, ProductService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $service->setImage($product, null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.image_removed')]);

        return back(303);
    }

    /**
     * Image servie hors racine web, uniquement via URL signée et dans l'entreprise active.
     */
    public function image(Product $product): StreamedResponse
    {
        Gate::authorize('catalog.view');

        return CatalogImages::response($product->image_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'type' => $product->type,
            'sale_price' => $product->sale_price,
            'unit' => $product->unit->code,
            'category' => $product->category?->name,
            'barcode' => $product->barcodes->firstWhere('is_primary', true)?->code,
            'archived' => $product->archived_at !== null,
            'image_url' => $this->imageUrl($product),
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        return CatalogImages::url('products.image', $product->image_path, ['product' => $product->id], $product->version);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?Product $product): array
    {
        $product?->load(['barcodes' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('created_at')]);

        return [
            'product' => $product ? [
                ...$product->only(['id', 'sku', 'name', 'description', 'type', 'category_id', 'unit_id', 'tax_rate_id', 'sale_price', 'standard_cost', 'version']),
                'archived' => $product->archived_at !== null,
                'image_url' => $this->imageUrl($product),
                'barcodes' => $product->barcodes->map(fn (ProductBarcode $b) => $b->only(['id', 'code', 'symbology', 'is_primary']))->values(),
            ] : null,
            'categories' => ProductCategory::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'taxRates' => TaxRate::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'rate_basis_points', 'is_default']),
            'can' => [
                'create' => Gate::allows('catalog.create'),
                'update' => Gate::allows('catalog.update'),
                'archive' => Gate::allows('catalog.archive'),
                'price_override' => Gate::allows('catalog.price_override'),
            ],
        ];
    }
}
