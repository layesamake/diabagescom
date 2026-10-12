<?php

namespace App\Console\Commands;

use App\Domain\Storefront\StorefrontCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StorefrontProduct;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use GdImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ajoute des articles d'exemple (avec une image générée) à la boutique en ligne
 * de l'entreprise d'un utilisateur. Idempotent : un article déjà présent
 * (même référence) n'est pas recréé.
 */
#[Signature('diaba:storefront:seed {email : Adresse de l’utilisateur membre de l’entreprise} {--draft : Laisser les articles en brouillon au lieu de les publier}')]
#[Description('Crée 10 articles d’exemple avec images dans la boutique en ligne d’un utilisateur')]
class StorefrontSeedCommand extends Command
{
    /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5: array{0: int, 1: int, 2: int}}> */
    private const array ITEMS = [
        ['BTQ-001', 'Riz parfumé 25 kg', 'Épicerie', 14500, 'Riz long grain parfumé, sac de 25 kg.', [214, 158, 46]],
        ['BTQ-002', 'Huile végétale 5 L', 'Épicerie', 6500, 'Huile végétale raffinée, bidon de 5 litres.', [230, 190, 40]],
        ['BTQ-003', 'Sucre en poudre 1 kg', 'Épicerie', 750, 'Sucre blanc en poudre, paquet de 1 kg.', [120, 144, 156]],
        ['BTQ-004', 'Lait en poudre 400 g', 'Épicerie', 2100, 'Lait entier en poudre, boîte de 400 g.', [66, 133, 200]],
        ['BTQ-005', 'Thé vert 250 g', 'Boissons', 1500, 'Thé vert en feuilles, boîte de 250 g.', [76, 150, 90]],
        ['BTQ-006', 'Café moulu 500 g', 'Boissons', 3200, 'Café moulu torréfié, paquet de 500 g.', [110, 72, 52]],
        ['BTQ-007', 'Eau minérale 1,5 L', 'Boissons', 500, 'Bouteille d’eau minérale de 1,5 litre.', [52, 152, 219]],
        ['BTQ-008', 'Savon de Marseille', 'Hygiène', 400, 'Savon de Marseille traditionnel, 300 g.', [140, 190, 150]],
        ['BTQ-009', 'Lessive en poudre 2 kg', 'Hygiène', 3800, 'Lessive en poudre pour le linge, sac de 2 kg.', [90, 110, 200]],
        ['BTQ-010', 'Cahier 200 pages', 'Papeterie', 900, 'Cahier grands carreaux de 200 pages.', [200, 90, 90]],
    ];

    public function handle(TenantContext $context, StorefrontCatalog $catalog): int
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            $this->error('L’extension GD avec WebP est requise pour générer les images.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        $tenant = $user?->memberships()->with('tenant')->get()->pluck('tenant')->filter()->first();

        if (! $tenant instanceof Tenant) {
            $this->error('Aucun utilisateur ou aucune entreprise trouvée pour cette adresse.');

            return self::FAILURE;
        }

        $publish = ! $this->option('draft');
        $created = $context->runAs($tenant, function () use ($tenant, $catalog, $publish): int {
            $unit = Unit::query()->where('code', 'U')->value('id');
            $tax = TaxRate::query()->where('is_default', true)->value('id') ?? TaxRate::query()->value('id');
            $storefront = $catalog->storefrontFor($tenant);
            $created = 0;

            foreach (self::ITEMS as [$sku, $name, $category, $price, $description, $color]) {
                $product = Product::query()->where('sku', $sku)->first();

                if ($product === null) {
                    $product = new Product;
                    $product->forceFill([
                        'sku' => $sku, 'name' => $name, 'description' => $description, 'type' => 'stockable',
                        'unit_id' => $unit, 'tax_rate_id' => $tax, 'sale_price' => $price,
                        'standard_cost' => (int) round($price * 0.8),
                        'category_id' => ProductCategory::query()->firstOrCreate(['name' => $category])->id,
                        'image_path' => $this->image($tenant->id, $name, $color),
                    ])->save();
                    $created++;
                }

                $listing = StorefrontProduct::query()->firstOrCreate(
                    ['storefront_id' => $storefront->id, 'product_id' => $product->id],
                    ['tenant_id' => $tenant->id, 'visible' => $publish],
                );
                if (! $listing->wasRecentlyCreated && $publish && ! $listing->visible) {
                    $listing->update(['visible' => true]);
                }
            }

            return $created;
        });

        $this->info("{$created} article(s) créé(s) pour « {$tenant->display_name} », ".($publish ? 'publiés' : 'en brouillon').'.');

        return self::SUCCESS;
    }

    /**
     * Image carrée (vignette colorée avec le nom), enregistrée en WebP sur le disque privé.
     *
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private function image(string $tenantId, string $name, array $color): string
    {
        $size = 600;
        $image = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = $color;

        for ($y = 0; $y < $size; $y++) {
            $shade = 1 - ($y / $size) * 0.35;
            imageline($image, 0, $y, $size, $y, (int) imagecolorallocate($image, min(255, max(0, (int) ($r * $shade))), min(255, max(0, (int) ($g * $shade))), min(255, max(0, (int) ($b * $shade)))));
        }

        imagefilledellipse($image, 300, 230, 280, 280, (int) imagecolorallocatealpha($image, 255, 255, 255, 96));
        $this->scaledText($image, strtoupper(substr(Str::ascii($name), 0, 1)), 300, 230, 22);

        foreach (array_slice(explode("\n", wordwrap(Str::ascii($name), 20, "\n", true)), 0, 3) as $i => $text) {
            $this->scaledText($image, $text, 300, 440 + $i * 44, 3);
        }

        ob_start();
        imagewebp($image, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        $path = "tenants/{$tenantId}/products/".Str::uuid7().'.webp';
        Storage::disk('local')->put($path, $data);

        return $path;
    }

    /** Texte blanc centré et agrandi (police GD intégrée, sans fichier de police). */
    private function scaledText(GdImage $image, string $text, int $cx, int $cy, int $scale): void
    {
        $font = 5;
        $w = max(1, imagefontwidth($font) * strlen($text));
        $h = max(1, imagefontheight($font));
        $tile = imagecreatetruecolor($w, $h);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagefill($tile, 0, 0, (int) imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagestring($tile, $font, 0, 0, $text, (int) imagecolorallocate($tile, 255, 255, 255));

        $targetW = $w * $scale;
        $targetH = $h * $scale;
        if ($targetW > 560) {
            $targetH = (int) ($targetH * 560 / $targetW);
            $targetW = 560;
        }

        imagealphablending($image, true);
        imagecopyresized($image, $tile, (int) ($cx - $targetW / 2), (int) ($cy - $targetH / 2), 0, 0, $targetW, $targetH, $w, $h);
        imagedestroy($tile);
    }
}
