<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Catalog\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Simple\SimpleProductRequest;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 5 : création tactile d'un article stockable.
 * La création reste déléguée à ProductService pour conserver l'audit,
 * la taxe par défaut, l'abonnement et l'isolation par entreprise.
 */
class SimpleProductController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function create(): Response
    {
        Gate::authorize('catalog.create');

        return Inertia::render('simple/NouvelArticle', [
            'categories' => ProductCategory::query()
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']),
            'units' => Unit::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(SimpleProductRequest $request, ProductService $products): RedirectResponse
    {
        Gate::authorize('catalog.create');

        /** @var array{name: string, sale_price: int, unit_id: string, category_id?: string|null, image?: UploadedFile|null} $data */
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $product = $products->createSimple($data, $user);

        if ($request->hasFile('image')) {
            $products->setImage($product, $request->file('image'));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "L’article {$product->name} est enregistré.",
        ]);

        return to_route('simple.products.create');
    }
}
