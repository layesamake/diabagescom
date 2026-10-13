<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\BarcodeSvg;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Étiquettes à code-barres : page d'impression (planche A4 ou rouleau
 * d'étiquettes) pour les articles choisis. Le dessin est produit ici, sans
 * service externe. Les articles sans code-barres sont signalés, pas imprimés.
 */
class LabelController extends Controller
{
    /** Formats d'étiquette (largeur × hauteur, en mm). */
    public const array SIZES = ['50x30', '70x40', '40x25'];

    public const int MAX_PRODUCTS = 200;

    public const int MAX_LABELS = 500;

    public function show(Request $request): Response
    {
        Gate::authorize('catalog.view');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'ids.*' => ['uuid'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'size' => ['sometimes', 'in:'.implode(',', self::SIZES)],
            'layout' => ['sometimes', 'in:sheet,roll'],
            'price' => ['sometimes', 'boolean'],
        ]);

        $copies = (int) ($data['copies'] ?? 1);
        $ids = array_values(array_unique($data['ids']));
        abort_if(count($ids) * $copies > self::MAX_LABELS, 422, __('catalog.labels.too_many', ['max' => self::MAX_LABELS]));

        // Le scope entreprise écarte tout article d'une autre entreprise.
        $products = Product::query()->with('barcodes')->whereIn('id', $ids)->get()->keyBy('id');
        $labels = [];
        $skipped = [];

        foreach ($ids as $id) {
            /** @var Product|null $product */
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            /** @var ProductBarcode|null $barcode */
            $barcode = $product->barcodes->firstWhere('is_primary', true) ?? $product->barcodes->first();

            try {
                $svg = $barcode !== null ? BarcodeSvg::render($barcode->code, $barcode->symbology) : null;
            } catch (InvalidArgumentException) {
                $svg = null;
            }

            if ($barcode === null || $svg === null) {
                $skipped[] = $product->name;

                continue;
            }

            $labels[] = [
                'name' => $product->name, 'sku' => $product->sku, 'price' => $product->sale_price,
                'code' => $barcode->code, 'svg' => $svg, 'square' => $barcode->symbology === 'qr',
            ];
        }

        $size = (string) ($data['size'] ?? self::SIZES[0]);
        [$width, $height] = array_map('intval', explode('x', $size));

        return response()->view('print.labels', [
            'labels' => $labels,
            'copies' => $copies,
            'skipped' => $skipped,
            'width' => $width,
            'height' => $height,
            'roll' => ($data['layout'] ?? 'sheet') === 'roll',
            'showPrice' => $request->boolean('price', true),
            'autoprint' => $request->boolean('print'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
