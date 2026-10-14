<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAccess;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recherche globale (Ctrl+K) : articles, clients, fournisseurs et documents de
 * vente. Chaque groupe n'est cherché que si l'utilisateur a le droit de le
 * consulter, avec les mêmes règles de visibilité que les listes (dépôts
 * accessibles pour les documents). Peu de résultats par groupe : c'est un
 * raccourci, pas un rapport.
 */
class SearchController extends Controller
{
    private const int LIMIT = 5;

    public function __invoke(Request $request, StockAccess $access): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $empty = ['products' => [], 'customers' => [], 'suppliers' => [], 'documents' => []];

        if (mb_strlen($q) < 2) {
            return response()->json($empty);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        $products = Gate::allows('catalog.view')
            ? Product::query()->whereNull('archived_at')->search($q)->orderBy('name')->limit(self::LIMIT)->get(['id', 'sku', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->name, 'detail' => $p->sku])->all()
            : [];

        $customers = Gate::allows('customer.view')
            ? $this->parties(Customer::query(), $q, $like)->map(fn (Customer $c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->code])->all()
            : [];

        $suppliers = Gate::allows('supplier.view')
            ? $this->parties(Supplier::query(), $q, $like)->map(fn (Supplier $s) => ['id' => $s->id, 'label' => $s->name, 'detail' => $s->code])->all()
            : [];

        $documents = Gate::allows('sale.view')
            ? SalesDocument::query()
                ->with('customer:id,name')
                ->whereIn('warehouse_id', $access->accessibleIds())
                ->where(fn (Builder $w) => $w
                    ->whereRaw('upper(number) LIKE upper(?)', [$like])
                    ->orWhereHas('customer', fn (Builder $c) => $c->whereRaw('diaba_search_text(name) LIKE diaba_search_text(?)', [$like])))
                ->latest()->limit(self::LIMIT)->get(['id', 'type', 'number', 'status', 'customer_id'])
                ->map(fn (SalesDocument $d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'label' => $d->number,
                    'detail' => $d->customer?->name,
                ])->all()
            : [];

        return response()->json(['products' => $products, 'customers' => $customers, 'suppliers' => $suppliers, 'documents' => $documents]);
    }

    /**
     * @template TModel of Customer|Supplier
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function parties(Builder $query, string $q, string $like): Collection
    {
        return $query->whereNull('archived_at')
            ->where(fn (Builder $w) => $w
                ->whereRaw('diaba_search_text(name) LIKE diaba_search_text(?)', [$like])
                ->orWhereRaw('upper(code) = upper(?)', [$q])
                ->orWhere('phone', 'like', $like))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'code', 'name']);
    }
}
