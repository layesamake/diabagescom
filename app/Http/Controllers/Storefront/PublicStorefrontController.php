<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CreateStorefrontOrderRequest;
use App\Models\Product;
use App\Models\Storefront;
use App\Models\StorefrontOrder;
use App\Models\StorefrontProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicStorefrontController extends Controller
{
    public function show(Request $request, string $slug, FeatureAccess $access): Response
    {
        $storefront = $this->activeStorefront($slug, $access);
        $q = trim((string) $request->query('q', ''));
        $listings = StorefrontProduct::query()
            ->where('storefront_id', $storefront->id)->where('visible', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        // Les relations unités/catégories restent filtrées par l'entreprise de la boutique,
        // même pour un visiteur sans session ou connecté à une autre entreprise.
        $productsById = app(TenantContext::class)->runAs(
            $storefront->tenant,
            function () use ($storefront, $listings, $q) {
                $productQuery = Product::query()->with(['unit:id,code', 'category:id,name'])
                    ->where('tenant_id', $storefront->tenant_id)->where('active', true)->whereNull('archived_at')
                    ->whereIn('id', $listings->pluck('product_id'));
                if ($q !== '') {
                    $productQuery->search($q);
                }

                return $productQuery->get()->keyBy('id');
            },
        );
        $available = $listings->filter(fn (StorefrontProduct $listing) => $productsById->has($listing->product_id));
        // Menu des catégories : celles des articles publiés (avant filtre par catégorie).
        $categories = $available->map(fn (StorefrontProduct $listing) => $productsById->get($listing->product_id)->category)
            ->filter()->unique('id')->sortBy('name')
            ->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name])->values();
        $categoryId = (string) $request->query('category', '');
        if ($categoryId !== '') {
            $available = $available->filter(fn (StorefrontProduct $listing) => $productsById->get($listing->product_id)->category_id === $categoryId);
        }
        $products = $available->map(function (StorefrontProduct $listing) use ($productsById, $slug): array {
            /** @var Product $product */
            $product = $productsById->get($listing->product_id);

            return $this->productData($listing, $product, $slug);
        })->values();

        return Inertia::render('storefront/Public', [
            'storefront' => ['name' => $storefront->tenant->display_name, ...$storefront->only(['slug', 'tagline', 'description', 'phone', 'email', 'whatsapp', 'address', 'opening_hours', 'accepts_orders', 'delivery_enabled'])],
            'products' => ['data' => $products, 'prev_page_url' => null, 'next_page_url' => null],
            'categories' => $categories,
            'filters' => ['q' => $q, 'category' => $categoryId],
        ]);
    }

    public function order(CreateStorefrontOrderRequest $request, string $slug, FeatureAccess $access): RedirectResponse
    {
        $storefront = $this->activeStorefront($slug, $access);
        abort_unless($storefront->accepts_orders, 404);
        /** @var array{customer_name: string, customer_phone: string, customer_email?: string|null, delivery_address?: string|null, fulfillment: string, notes?: string|null, items: list<array{product_id: string, quantity: int|float|string}>} $data */
        $data = $request->validated();
        $ids = collect(array_values(array_unique(array_map(
            fn (array $item): string => $item['product_id'],
            $data['items'],
        ))));
        $listings = StorefrontProduct::query()->where('storefront_id', $storefront->id)->where('visible', true)
            ->whereIn('product_id', $ids)->get()->keyBy('product_id');
        $products = Product::query()->withoutGlobalScopes()->where('tenant_id', $storefront->tenant_id)
            ->where('active', true)->whereNull('archived_at')->whereIn('id', $ids)->get()->keyBy('id');

        if ($listings->count() !== $ids->count() || $products->count() !== $ids->count()) {
            throw ValidationException::withMessages(['items' => 'Un article sélectionné n’est plus disponible.']);
        }

        $order = DB::transaction(function () use ($storefront, $data, $listings, $products): StorefrontOrder {
            $number = $this->nextNumber($storefront->tenant_id);
            $items = [];
            $total = 0;
            foreach ($data['items'] as $line) {
                /** @var StorefrontProduct $listing */
                $listing = $listings->get($line['product_id']);
                $product = $products->get($listing->product_id);
                $unitPrice = $listing->public_price ?? $product->sale_price;
                $quantity = (float) $line['quantity'];
                $subtotal = (int) round($unitPrice * $quantity);
                $total += $subtotal;
                $items[] = [
                    'tenant_id' => $storefront->tenant_id, 'product_id' => $product->id,
                    'product_name' => $listing->public_name ?: $product->name, 'sku' => $product->sku,
                    'quantity' => $quantity, 'unit_price' => $unitPrice, 'subtotal' => $subtotal,
                ];
            }
            $order = StorefrontOrder::query()->create([
                'tenant_id' => $storefront->tenant_id, 'storefront_id' => $storefront->id,
                'number' => $number, 'status' => 'new', 'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'], 'customer_email' => $data['customer_email'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null, 'fulfillment' => $data['fulfillment'],
                'notes' => $data['notes'] ?? null, 'total' => $total,
            ]);
            $order->items()->createMany($items);

            return $order;
        });

        return to_route('storefront.order.confirmation', ['slug' => $slug, 'order' => $order->number]);
    }

    public function confirmation(string $slug, string $order, FeatureAccess $access): Response
    {
        $storefront = $this->activeStorefront($slug, $access);
        $record = StorefrontOrder::query()->with('items')->where('storefront_id', $storefront->id)->where('number', $order)->firstOrFail();

        return Inertia::render('storefront/Confirmation', ['storefront' => $storefront->only(['slug', 'phone', 'email', 'whatsapp']), 'order' => $record]);
    }

    public function image(string $slug, string $product, FeatureAccess $access): StreamedResponse
    {
        $storefront = $this->activeStorefront($slug, $access);
        $listing = StorefrontProduct::query()->where('storefront_id', $storefront->id)->where('product_id', $product)->where('visible', true)->firstOrFail();
        $record = Product::query()->withoutGlobalScopes()->where('tenant_id', $storefront->tenant_id)->findOrFail($product);
        abort_unless($record->image_path !== null, 404);

        return CatalogImages::response($record->image_path);
    }

    private function activeStorefront(string $slug, FeatureAccess $access): Storefront
    {
        $storefront = Storefront::query()->where('slug', $slug)->where('enabled', true)->firstOrFail();
        $status = $access->status($storefront->tenant);
        abort_unless(in_array($status, ['trialing', 'active', 'read_only'], true), 404);

        return $storefront;
    }

    /** @return array<string, mixed> */
    private function productData(StorefrontProduct $listing, Product $product, string $slug): array
    {
        return [
            'id' => $product->id, 'sku' => $product->sku,
            'name' => $listing->public_name ?: $product->name,
            'description' => $listing->public_description ?: $product->description,
            'category' => $product->category?->name,
            'price' => $listing->public_price ?? $product->sale_price,
            'unit' => $product->unit->code,
            'image_url' => $product->image_path ? route('storefront.products.image', [$slug, $product->id]) : null,
        ];
    }

    private function nextNumber(string $tenantId): string
    {
        $sequence = DB::table('tenant_sequences')->where('tenant_id', $tenantId)->where('key', 'storefront_orders')->lockForUpdate()->first();
        if ($sequence === null) {
            DB::table('tenant_sequences')->insert(['tenant_id' => $tenantId, 'key' => 'storefront_orders', 'next_value' => 2, 'created_at' => now(), 'updated_at' => now()]);

            return 'WEB-000001';
        }
        DB::table('tenant_sequences')->where('tenant_id', $tenantId)->where('key', 'storefront_orders')->update(['next_value' => $sequence->next_value + 1, 'updated_at' => now()]);

        return 'WEB-'.str_pad((string) $sequence->next_value, 6, '0', STR_PAD_LEFT);
    }
}
