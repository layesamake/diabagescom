<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\SalesDocumentService;
use App\Domain\Sync\OfflineSync;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Models\Customer;
use App\Models\OfflineDevice;
use App\Models\Product;
use App\Models\SyncCommand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API de synchronisation de la PWA (session navigateur + appareil enregistré).
 * Toujours revalidée côté serveur : l'appareil ne décide jamais seul.
 */
class SyncController extends Controller
{
    public function register(Request $request, OfflineSync $sync): JsonResponse
    {
        Gate::authorize('sale.create');
        $data = $request->validate(['device_key' => ['required', 'uuid'], 'label' => ['nullable', 'string', 'max:120']]);

        /** @var User $user */
        $user = $request->user();
        $device = $sync->register($user, (string) $data['device_key'], (string) ($data['label'] ?? ''), $request->userAgent());

        return response()->json(['device' => ['id' => $device->id, 'label' => $device->label]]);
    }

    /**
     * Catalogue minimal du dépôt pour vendre hors connexion. Incrémental : avec
     * `since`, seuls les articles et clients modifiés depuis (y compris archivés,
     * pour suppression locale). Les soldes de stock sont toujours complets.
     *
     * Paginé par identifiant (curseurs `after_products` / `after_customers`, `limit` par
     * page, 5 000 au plus) : l'appareil enchaîne les pages tant que `has_more` est vrai et
     * n'enregistre le curseur de synchronisation qu'à la dernière, pour qu'aucun article
     * ne soit jamais perdu au-delà de la taille de page. Stock et caisses ouvertes ne sont
     * envoyés qu'avec la première page.
     */
    public function catalog(Request $request, OfflineSync $sync, StockAccess $access, SalesDocumentService $sales): JsonResponse
    {
        Gate::authorize('sale.create');
        /** @var User $user */
        $user = $request->user();
        $sync->device($user, $request->header('X-Device-Key'));

        $data = $request->validate([
            'warehouse' => ['required', 'uuid'],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'after_products' => ['nullable', 'uuid'],
            'after_customers' => ['nullable', 'uuid'],
        ]);
        $warehouse = $access->warehouse((string) $data['warehouse']);
        $limit = (int) ($data['limit'] ?? 5000);
        $firstPage = ! isset($data['after_products']) && ! isset($data['after_customers']);
        $since = isset($data['since']) ? CarbonImmutable::parse((string) $data['since'])->subSeconds(5) : null;
        $cursor = now()->toIso8601String();

        // On lit une ligne de plus que la page pour savoir s'il en reste, sans requête de comptage.
        $productRows = Product::query()->with(['unit:id,code,decimals_allowed', 'taxRate:id,rate_basis_points', 'barcodes:id,product_id,code'])
            ->when($since === null, fn ($q) => $q->whereNull('archived_at')->where('active', true))
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->when(isset($data['after_products']), fn ($q) => $q->where('id', '>', $data['after_products']))
            ->orderBy('id')->limit($limit + 1)->get();
        $moreProducts = $productRows->count() > $limit;
        $productRows = $productRows->take($limit);
        $products = $productRows
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'type' => $p->type,
                'unit' => $p->unit->code,
                'decimals' => $p->unit->decimals_allowed,
                'sale_price' => $p->sale_price,
                'tax_rate_basis_points' => $p->taxRate->rate_basis_points,
                'barcodes' => $p->barcodes->pluck('code')->values(),
                'removed' => $p->archived_at !== null || ! $p->active,
            ]);

        $customerRows = Customer::query()
            ->when($since === null, fn ($q) => $q->whereNull('archived_at'))
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->when(isset($data['after_customers']), fn ($q) => $q->where('id', '>', $data['after_customers']))
            ->orderBy('id')->limit($limit + 1)->get(['id', 'code', 'name', 'phone', 'credit_limit', 'archived_at']);
        $moreCustomers = $customerRows->count() > $limit;
        $customerRows = $customerRows->take($limit);
        $customers = $customerRows
            ->map(fn (Customer $c) => [
                ...$c->only(['id', 'code', 'name', 'phone', 'credit_limit']),
                'outstanding' => $sales->outstanding($c->id),
                'removed' => $c->archived_at !== null,
            ]);

        $stock = $firstPage ? DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->pluck('quantity_on_hand', 'product_id')
            ->map(fn ($q) => Quantity::of((string) $q)->format()) : null;

        return response()->json([
            'cursor' => $cursor,
            'full' => $since === null,
            'first_page' => $firstPage,
            'has_more' => $moreProducts || $moreCustomers,
            'next_after_products' => $productRows->isNotEmpty() ? $productRows->last()->id : ($data['after_products'] ?? null),
            'next_after_customers' => $customerRows->isNotEmpty() ? $customerRows->last()->id : ($data['after_customers'] ?? null),
            'warehouse' => $warehouse->only(['id', 'code', 'name']),
            'products' => $products->values(),
            'customers' => $customers->values(),
            'stock' => $stock,
            'sessions' => $firstPage ? SalesDocumentController::openSessions($warehouse->id) : null,
        ]);
    }

    public function commands(Request $request, OfflineSync $sync): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = $sync->device($user, $request->header('X-Device-Key'));

        $data = $request->validate([
            'commands' => ['required', 'array', 'min:1', 'max:50'],
            'commands.*.command_id' => ['required', 'uuid'],
            'commands.*.type' => ['required', 'string', 'max:32'],
            'commands.*.created_at' => ['nullable', 'string', 'max:40'],
            'commands.*.payload' => ['required', 'array'],
        ]);

        // Dans l'ordre de création : les commandes dépendantes passent après leur source.
        $results = [];

        foreach ($data['commands'] as $command) {
            $results[] = $sync->process($device, $user, $command);
        }

        return response()->json(['results' => $results]);
    }

    public function devices(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $all = Gate::allows('member.manage');

        $devices = OfflineDevice::query()->with('user:id,name')
            ->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->latest('last_seen_at')->get()
            ->map(fn (OfflineDevice $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'user' => $d->user->name,
                'mine' => $d->user_id === $user->id,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'revoked_at' => $d->revoked_at?->toIso8601String(),
                'pending_review' => SyncCommand::query()->where('device_id', $d->id)->where('status', '<>', SyncCommand::ACCEPTED)->count(),
            ]);

        return Inertia::render('settings/Devices', ['devices' => $devices]);
    }

    public function revoke(Request $request, OfflineDevice $device, OfflineSync $sync): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($device->user_id === $user->id || Gate::allows('member.manage'), 403);

        $sync->revoke($device, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sync.saved.revoked', ['device' => $device->label])]);

        return back(303);
    }
}
