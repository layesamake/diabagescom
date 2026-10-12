<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\UpdateStorefrontRequest;
use App\Models\Product;
use App\Models\Storefront;
use App\Models\StorefrontOrder;
use App\Models\StorefrontProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    public function index(TenantContext $context, FeatureAccess $access): Response
    {
        Gate::authorize('catalog.view');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        $storefront = $this->storefront($context);

        return Inertia::render('storefront/Index', [
            'storefront' => $this->storefrontData($storefront),
            'publicUrl' => route('storefront.show', $storefront->slug),
            'productsCount' => StorefrontProduct::query()->where('storefront_id', $storefront->id)->where('visible', true)->count(),
            'ordersCount' => StorefrontOrder::query()->where('storefront_id', $storefront->id)->whereIn('status', ['new', 'confirmed', 'preparing'])->count(),
            'canManage' => Gate::allows('catalog.update'),
        ]);
    }

    public function update(UpdateStorefrontRequest $request, TenantContext $context, FeatureAccess $access): RedirectResponse
    {
        Gate::authorize('catalog.update');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        $storefront = $this->storefront($context);
        $storefront->update($request->validated());

        return back(303)->with('success', 'Boutique enregistrée.');
    }

    public function products(TenantContext $context, FeatureAccess $access): Response
    {
        Gate::authorize('catalog.view');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        $storefront = $this->storefront($context);
        $products = Product::query()->with(['unit:id,code', 'category:id,name'])
            ->whereNull('archived_at')->orderBy('name')->get()
            ->map(function (Product $product) use ($storefront): array {
                $listing = StorefrontProduct::query()->firstOrCreate(
                    ['storefront_id' => $storefront->id, 'product_id' => $product->id],
                    ['tenant_id' => $product->tenant_id],
                );

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'sale_price' => $product->sale_price,
                    'unit' => $product->unit->code,
                    'visible' => $listing->visible,
                    'public_name' => $listing->public_name,
                    'public_description' => $listing->public_description,
                    'public_price' => $listing->public_price,
                ];
            });

        return Inertia::render('storefront/Products', [
            'storefront' => $this->storefrontData($storefront),
            'products' => $products,
        ]);
    }

    public function updateProduct(Request $request, Product $product, TenantContext $context, FeatureAccess $access): RedirectResponse
    {
        Gate::authorize('catalog.update');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        $data = $request->validate([
            'visible' => ['required', 'boolean'],
            'public_name' => ['nullable', 'string', 'max:160'],
            'public_description' => ['nullable', 'string', 'max:5000'],
            'public_price' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
        ]);
        $storefront = $this->storefront($context);
        StorefrontProduct::query()->updateOrCreate(
            ['storefront_id' => $storefront->id, 'product_id' => $product->id],
            ['tenant_id' => $product->tenant_id, ...$data],
        );

        return back(303);
    }

    public function orders(Request $request, TenantContext $context, FeatureAccess $access): Response
    {
        Gate::authorize('sale.view');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        $storefront = $this->storefront($context);
        $status = (string) $request->query('status', '');
        $orders = StorefrontOrder::query()->where('storefront_id', $storefront->id)
            ->when(in_array($status, StorefrontOrder::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->withCount('items')->orderByDesc('created_at')->paginate(25)->withQueryString();

        return Inertia::render('storefront/Orders', [
            'orders' => $orders,
            'statuses' => StorefrontOrder::STATUSES,
            'filters' => ['status' => $status],
        ]);
    }

    public function updateOrder(Request $request, StorefrontOrder $order, TenantContext $context, FeatureAccess $access): RedirectResponse
    {
        Gate::authorize('sale.update_draft');
        $access->assertFeature($context->tenant(), FeatureAccess::FEATURE_ONLINE_STORE);
        abort_unless($order->storefront->tenant_id === $context->tenant()->id, 404);
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', StorefrontOrder::STATUSES)]]);
        $order->update([
            'status' => $data['status'],
            'confirmed_at' => in_array($data['status'], ['confirmed', 'preparing', 'ready', 'completed'], true) ? ($order->confirmed_at ?? now()) : $order->confirmed_at,
            'completed_at' => $data['status'] === 'completed' ? now() : $order->completed_at,
        ]);

        return back(303);
    }

    private function storefront(TenantContext $context): Storefront
    {
        return Storefront::query()->firstOrCreate(
            ['tenant_id' => $context->tenant()->id],
            ['slug' => $this->uniqueSlug($context->tenant()->display_name), 'enabled' => false],
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'boutique';
        $slug = $base;
        $suffix = 2;
        while (Storefront::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function storefrontData(Storefront $storefront): array
    {
        return $storefront->only([
            'id', 'slug', 'enabled', 'tagline', 'description', 'phone', 'email', 'whatsapp',
            'address', 'opening_hours', 'accepts_orders', 'delivery_enabled',
        ]);
    }
}
