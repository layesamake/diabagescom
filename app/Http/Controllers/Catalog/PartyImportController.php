<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CsvTable;
use App\Domain\Catalog\PartyImport;
use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import CSV de clients ou de fournisseurs (même écran, paramétré par le type de
 * tiers de la route) : aperçu, puis application.
 */
class PartyImportController extends Controller
{
    public function create(Request $request): Response
    {
        $type = $this->type($request);

        return Inertia::render('catalog/PartyImport', [
            'type' => $type,
            'batch' => null,
            'recent' => CatalogImport::query()->where('type', PartyImport::TYPES[$type])->latest()->limit(10)
                ->get(['id', 'file_name', 'status', 'rows_total', 'rows_valid', 'created_at', 'applied_at']),
        ]);
    }

    public function store(Request $request, PartyImport $importer): RedirectResponse
    {
        $type = $this->type($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], attributes: ['file' => 'fichier']);

        /** @var User $user */
        $user = $request->user();
        $import = $importer->preview($type, $request->file('file'), $user);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('catalog.import.previewed')]);

        return to_route("{$this->path($type)}.import.show", $import->id);
    }

    public function show(Request $request, string $import): Response
    {
        $type = $this->type($request);
        $model = CatalogImport::query()->where('type', PartyImport::TYPES[$type])->findOrFail($import);

        return Inertia::render('catalog/PartyImport', [
            'type' => $type,
            'batch' => $model->only(['id', 'file_name', 'status', 'rows_total', 'rows_valid', 'errors', 'summary', 'applied_at', 'created_at']),
            'recent' => [],
        ]);
    }

    public function apply(Request $request, string $import, PartyImport $importer): RedirectResponse
    {
        $type = $this->type($request);
        $model = CatalogImport::query()->where('type', PartyImport::TYPES[$type])->findOrFail($import);
        $result = $importer->apply($type, $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.import.applied', $result)]);

        return to_route("{$this->path($type)}.import.show", $import);
    }

    /**
     * Fichier modèle à remplir (ouvrable directement dans Excel).
     */
    public function template(Request $request): StreamedResponse
    {
        $type = $this->type($request);

        $header = ['code', 'nom', 'telephone', 'email', 'adresse', 'ville', 'ninea', 'delai_paiement', 'notes'];
        $rows = $type === 'customer'
            ? [[...$header, 'plafond_credit'], ['', 'Boutique Aminata', '77 123 45 67', 'aminata@exemple.sn', '12 rue Carnot', 'Dakar', '006123456 2G2', '30', 'Client régulier', '500000']]
            : [$header, ['', 'Grossiste Diop', '33 800 00 00', 'contact@diop.sn', 'Zone industrielle', 'Dakar', '', '15', '']];

        return response()->streamDownload(function () use ($rows): void {
            echo CsvTable::template($rows);
        }, ($type === 'customer' ? 'modele-clients' : 'modele-fournisseurs').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return 'customer'|'supplier'
     */
    private function type(Request $request): string
    {
        $type = $request->route()?->defaults['party'] ?? null;
        abort_unless(in_array($type, ['customer', 'supplier'], true), 404);
        Gate::authorize("{$type}.manage");

        return $type;
    }

    private function path(string $type): string
    {
        return $type === 'customer' ? 'customers' : 'suppliers';
    }
}
