<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Organization\CompanyProfile;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support : fiche d'une entreprise cliente (logo, devanture, régime de TVA,
 * localisation) depuis la console plateforme.
 *
 * Chaque modification exige un motif et est tracée dans le journal d'audit de
 * l'entreprise. Le cachet/signature n'est jamais affiché ici (retrait seul) et
 * ne peut pas être remplacé par le support. Une entreprise suspendue peut être
 * corrigée : le support n'est pas soumis à l'état de l'abonnement.
 */
class TenantProfileController extends Controller
{
    /** Images que le support peut afficher et remplacer. */
    private const array VIEWABLE = ['logo', 'cover'];

    public function update(Request $request, Tenant $tenant, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        $data = $request->validate([
            'vat_regime' => ['required', Rule::in(TenantProfile::VAT_REGIMES)],
            'maps_url' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['vat_regime' => 'régime de TVA', 'maps_url' => 'lien Google Maps', 'reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $company->update($tenant, $data, $user, (string) $data['reason'], bypassSubscription: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.saved')]);

        return back(303);
    }

    public function uploadImage(Request $request, Tenant $tenant, string $slot, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        abort_unless(in_array($slot, self::VIEWABLE, true), 404);
        $data = $request->validate([
            'image' => CatalogImages::RULES,
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['image' => 'image', 'reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $company->setImage($tenant, $slot, $request->file('image'), $user, (string) $data['reason'], bypassSubscription: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.image_saved')]);

        return back(303);
    }

    public function removeImage(Request $request, Tenant $tenant, string $slot, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('platform.tenants.manage');
        abort_unless(array_key_exists($slot, TenantProfile::IMAGES), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], attributes: ['reason' => 'motif']);

        /** @var User $user */
        $user = $request->user();
        $company->setImage($tenant, $slot, null, $user, (string) $data['reason'], bypassSubscription: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.image_removed')]);

        return back(303);
    }

    public function image(Tenant $tenant, string $slot, CompanyProfile $company): StreamedResponse
    {
        Gate::authorize('platform.tenants.manage');
        abort_unless(in_array($slot, self::VIEWABLE, true), 404);

        return CatalogImages::response($company->of($tenant)->{TenantProfile::IMAGES[$slot]});
    }
}
