<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CsvTable;
use App\Domain\Catalog\ProductImport;
use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function create(): Response
    {
        $this->authorizeImport();

        return Inertia::render('catalog/ProductImport', [
            'batch' => null,
            'recent' => CatalogImport::query()->where('type', 'products')->latest()->limit(10)->get(['id', 'file_name', 'status', 'rows_total', 'rows_valid', 'created_at', 'applied_at']),
        ]);
    }

    public function store(Request $request, ProductImport $importer): RedirectResponse
    {
        $this->authorizeImport();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], attributes: ['file' => 'fichier']);

        /** @var User $user */
        $user = $request->user();
        $import = $importer->preview($request->file('file'), $user);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('catalog.import.previewed')]);

        return to_route('products.import.show', $import->id);
    }

    public function show(string $import): Response
    {
        $this->authorizeImport();

        $model = CatalogImport::query()->where('type', 'products')->findOrFail($import);

        return Inertia::render('catalog/ProductImport', [
            'batch' => $model->only(['id', 'file_name', 'status', 'rows_total', 'rows_valid', 'errors', 'summary', 'applied_at', 'created_at']),
            'recent' => [],
        ]);
    }

    public function apply(Request $request, string $import, ProductImport $importer): RedirectResponse
    {
        $this->authorizeImport();

        /** @var User $user */
        $user = $request->user();
        $result = $importer->apply(CatalogImport::query()->where('type', 'products')->findOrFail($import), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.import.applied', $result)]);

        return to_route('products.import.show', $import);
    }

    /**
     * Fichier modèle à remplir (ouvrable directement dans Excel).
     */
    public function template(): StreamedResponse
    {
        $this->authorizeImport();

        return response()->streamDownload(function (): void {
            echo CsvTable::template([
                ['sku', 'nom', 'unite', 'prix_vente', 'tva', 'categorie', 'code_barres', 'prix_achat', 'type', 'description'],
                ['RIZ25', 'Riz parfumé 25 kg', 'SAC', '17500', '18', 'Épicerie', '', '14000', 'article', ''],
                ['HUILE5', 'Huile 5 L', 'U', '6500', '18', 'Épicerie', '6111234567895', '5200', 'article', ''],
            ]);
        }, 'modele-articles.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeImport(): void
    {
        Gate::authorize('catalog.create');
        Gate::authorize('catalog.update');
    }
}
