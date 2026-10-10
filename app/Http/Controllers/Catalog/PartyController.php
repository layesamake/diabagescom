<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\PartyService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients et fournisseurs (même écran, paramétré par le type de tiers de la route).
 */
class PartyController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $this->type($request);
        Gate::authorize("{$type}.view");

        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active';

        $parties = $this->model($type)::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereRaw('diaba_search_text(name) LIKE diaba_search_text(?)', ['%'.addcslashes($q, '%_\\').'%'])
                ->orWhereRaw('upper(code) = upper(?)', [$q])
                ->orWhere('phone', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('catalog/Parties', [
            'type' => $type,
            'parties' => $parties,
            'filters' => ['q' => $q, 'status' => $status],
            'canManage' => Gate::allows("{$type}.manage"),
        ]);
    }

    public function store(Request $request, PartyService $service): RedirectResponse
    {
        $type = $this->type($request);
        Gate::authorize("{$type}.manage");

        $party = $service->create($this->model($type), $this->validated($request, $type, null));
        Inertia::flash('toast', ['type' => 'success', 'message' => __("catalog.party.{$type}_saved", ['name' => $party->name])]);

        return back(303);
    }

    public function update(Request $request, string $party, PartyService $service): RedirectResponse
    {
        $type = $this->type($request);
        Gate::authorize("{$type}.manage");

        /** @var Customer|Supplier $model */
        $model = $this->model($type)::query()->findOrFail($party);
        $service->update($model, $this->validated($request, $type, $model));
        Inertia::flash('toast', ['type' => 'success', 'message' => __("catalog.party.{$type}_saved", ['name' => $model->name])]);

        return back(303);
    }

    public function archive(Request $request, string $party, PartyService $service): RedirectResponse
    {
        $type = $this->type($request);
        Gate::authorize("{$type}.manage");

        /** @var Customer|Supplier $model */
        $model = $this->model($type)::query()->findOrFail($party);
        $archived = $request->boolean('archived', true);
        $service->setArchived($model, $archived);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($archived ? 'catalog.party.archived' : 'catalog.party.restored')]);

        return back(303);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $type, ?Model $existing): array
    {
        if (is_string($request->input('credit_limit'))) {
            $request->merge(['credit_limit' => preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $request->input('credit_limit'))]);
        }

        $table = $type === 'customer' ? 'customers' : 'suppliers';

        return $request->validate([
            'code' => [
                'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,19}$/',
                Rule::unique($table, 'code')->where('tenant_id', app(TenantContext::class)->tenantId())->ignore($existing?->getKey()),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+() .-]{6,32}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:40'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...($type === 'customer' ? ['credit_limit' => ['nullable', 'integer', 'min:0', 'max:9999999999999']] : []),
        ], [
            'credit_limit.integer' => 'Le plafond de crédit est un nombre entier en XOF.',
        ], [
            'code' => 'code',
            'name' => 'nom',
            'phone' => 'téléphone',
            'email' => 'adresse e-mail',
            'address_line' => 'adresse',
            'city' => 'ville',
            'tax_number' => 'NINEA',
            'payment_term_days' => 'délai de paiement',
            'credit_limit' => 'plafond de crédit',
        ]);
    }

    private function type(Request $request): string
    {
        $type = $request->route()?->defaults['party'] ?? null;
        abort_unless(in_array($type, ['customer', 'supplier'], true), 404);

        return $type;
    }

    /**
     * @return class-string<Customer>|class-string<Supplier>
     */
    private function model(string $type): string
    {
        return $type === 'customer' ? Customer::class : Supplier::class;
    }
}
