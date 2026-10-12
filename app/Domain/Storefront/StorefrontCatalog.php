<?php

namespace App\Domain\Storefront;

use App\Domain\Subscriptions\FeatureAccess;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\Storefront;
use App\Models\StorefrontProduct;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Alimente la boutique en ligne depuis le catalogue : tout article acheté est
 * inscrit en brouillon (non visible) ; le gestionnaire le publie ensuite.
 * Aucune ressaisie : nom, prix et photo viennent de la fiche article.
 */
final class StorefrontCatalog
{
    public function __construct(private readonly FeatureAccess $access) {}

    public function storefrontFor(Tenant $tenant): Storefront
    {
        return Storefront::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['slug' => $this->uniqueSlug($tenant->display_name), 'enabled' => false],
        );
    }

    /**
     * Inscrit en brouillon les articles donnés, sans toucher à ceux déjà inscrits.
     * Sans effet si la formule n'inclut pas la boutique en ligne.
     *
     * @param  iterable<string>  $productIds
     */
    public function draftProducts(Tenant $tenant, iterable $productIds): void
    {
        if (! $this->access->hasFeature($tenant, FeatureAccess::FEATURE_ONLINE_STORE)) {
            return;
        }

        $this->insertDrafts($this->storefrontFor($tenant), $tenant, array_values(array_unique(array_map('strval', [...$productIds]))));
    }

    /** Rattrape les articles déjà réceptionnés avant l'activation de la boutique. */
    public function syncPurchased(Storefront $storefront): void
    {
        $ids = array_values(array_map('strval', GoodsReceiptLine::query()
            ->whereIn('product_id', Product::query()->whereNull('archived_at')->select('id'))
            ->distinct()->pluck('product_id')->all()));

        $this->insertDrafts($storefront, $storefront->tenant, $ids);
    }

    /** @param  list<string>  $productIds */
    private function insertDrafts(Storefront $storefront, Tenant $tenant, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $known = StorefrontProduct::query()->where('storefront_id', $storefront->id)
            ->whereIn('product_id', $productIds)->pluck('product_id')->all();

        foreach (array_diff($productIds, $known) as $productId) {
            StorefrontProduct::query()->firstOrCreate(
                ['storefront_id' => $storefront->id, 'product_id' => $productId],
                ['tenant_id' => $tenant->id, 'visible' => false],
            );
        }
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
}
