<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ReferenceDataService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Référentiels du catalogue : catégories, unités, taux de taxe.
 * Les taxes relèvent du paramétrage fiscal de l'entreprise (organization.manage).
 */
class ReferenceDataController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('catalog.view');

        return Inertia::render('catalog/ReferenceData', [
            'categories' => ProductCategory::query()
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'image_path', 'version'])
                ->map(fn (ProductCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'parent_id' => $c->parent_id,
                    'image_url' => CatalogImages::url('catalog.categories.image', $c->image_path, ['category' => $c->id], $c->version),
                ]),
            'units' => Unit::query()->orderBy('name')->get(['id', 'code', 'name', 'decimals_allowed', 'active']),
            'taxRates' => TaxRate::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'rate_basis_points', 'is_default', 'active']),
            'can' => [
                'catalog' => Gate::allows('catalog.update'),
                'taxes' => Gate::allows('organization.manage'),
            ],
        ]);
    }

    public function saveCategory(Request $request, ReferenceDataService $service, ?string $category = null): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $tenantId = app(TenantContext::class)->tenantId();
        /** @var array{name: string, parent_id?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
        ], attributes: ['name' => 'nom', 'parent_id' => 'catégorie parente']);

        $service->saveCategory($category ? ProductCategory::query()->findOrFail($category) : null, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.category.saved')]);

        return back(303);
    }

    public function uploadCategoryImage(Request $request, ProductCategory $category, ReferenceDataService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $request->validate(['image' => CatalogImages::RULES], attributes: ['image' => 'photo']);
        $service->setCategoryImage($category, $request->file('image'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.category.image_updated')]);

        return back(303);
    }

    public function removeCategoryImage(ProductCategory $category, ReferenceDataService $service): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $service->setCategoryImage($category, null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.image_removed')]);

        return back(303);
    }

    /**
     * Photo servie hors racine web, uniquement via URL signée et dans l'entreprise active.
     */
    public function categoryImage(ProductCategory $category): StreamedResponse
    {
        Gate::authorize('catalog.view');

        return CatalogImages::response($category->image_path);
    }

    public function saveUnit(Request $request, ReferenceDataService $service, ?string $unit = null): RedirectResponse
    {
        Gate::authorize('catalog.update');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]{1,12}$/'],
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'decimals_allowed' => ['required', 'boolean'],
        ], attributes: ['code' => 'code', 'name' => 'nom']);

        $service->saveUnit($unit ? Unit::query()->findOrFail($unit) : null, [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'decimals_allowed' => (bool) $data['decimals_allowed'],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.unit.saved')]);

        return back(303);
    }

    public function saveTaxRate(Request $request, ReferenceDataService $service, ?string $taxRate = null): RedirectResponse
    {
        Gate::authorize('organization.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            // Saisie en pourcentage avec au plus 2 décimales (ex. 18 ou 2,5), stocké en points de base.
            'rate_percent' => ['required', 'string', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ], attributes: ['name' => 'nom', 'rate_percent' => 'taux']);

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', (string) $data['rate_percent'])), 2, '0');
        $basisPoints = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        abort_if($basisPoints > 10000, 422);

        $service->saveTaxRate($taxRate ? TaxRate::query()->findOrFail($taxRate) : null, [
            'name' => (string) $data['name'],
            'rate_basis_points' => $basisPoints,
            'is_default' => (bool) $data['is_default'],
            'active' => (bool) $data['active'],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.tax.saved')]);

        return back(303);
    }
}
