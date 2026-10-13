<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Organization\CompanyProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Catalog\CatalogImages;
use App\Http\Controllers\Controller;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fiche de l'entreprise : logo, cachet/signature, régime de TVA, localisation
 * Google Maps et devanture. Modifiable par qui gère l'organisation.
 */
class CompanyController extends Controller
{
    public function edit(TenantContext $context, CompanyProfile $company): Response
    {
        Gate::authorize('organization.view');
        $tenant = $context->tenant();
        $profile = $company->of($tenant);

        return Inertia::render('settings/Company', [
            'company' => ['legal_name' => $tenant->legal_name, 'display_name' => $tenant->display_name],
            'profile' => [
                'vat_regime' => $profile->vat_regime,
                'maps_url' => $profile->maps_url,
                ...$profile->only(TenantProfile::DETAILS),
                'images' => collect(TenantProfile::IMAGES)->map(fn (string $column, string $slot) => CatalogImages::url(
                    'company.images.show', $profile->{$column}, ['slot' => $slot], $profile->version,
                ))->all(),
            ],
            'regimes' => TenantProfile::VAT_REGIMES,
            'can' => ['manage' => Gate::allows('organization.manage')],
        ]);
    }

    public function update(Request $request, TenantContext $context, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:2', 'max:160'],
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'vat_regime' => ['required', Rule::in(TenantProfile::VAT_REGIMES)],
            'maps_url' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]*$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'ninea' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 .\/-]*$/'],
            'rccm' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 .\/-]*$/'],
            'document_footer' => ['nullable', 'string', 'max:300'],
        ], attributes: [
            'legal_name' => 'raison sociale', 'display_name' => 'nom commercial', 'vat_regime' => 'régime de TVA',
            'maps_url' => 'lien Google Maps', 'phone' => 'téléphone', 'email' => 'e-mail', 'address' => 'adresse',
            'city' => 'ville', 'ninea' => 'NINEA', 'rccm' => 'RCCM', 'document_footer' => 'mention de bas de page',
        ]);

        /** @var User $user */
        $user = $request->user();
        $company->update($context->tenant(), $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.saved')]);

        return back(303);
    }

    public function uploadImage(Request $request, string $slot, TenantContext $context, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $request->validate(['image' => CatalogImages::RULES], attributes: ['image' => 'image']);

        /** @var User $user */
        $user = $request->user();
        $company->setImage($context->tenant(), $slot, $request->file('image'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.image_saved')]);

        return back(303);
    }

    public function removeImage(Request $request, string $slot, TenantContext $context, CompanyProfile $company): RedirectResponse
    {
        Gate::authorize('organization.manage');

        /** @var User $user */
        $user = $request->user();
        $company->setImage($context->tenant(), $slot, null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.image_removed')]);

        return back(303);
    }

    /**
     * Image servie hors racine web, par lien signé, pour l'entreprise active.
     */
    public function image(string $slot, TenantContext $context, CompanyProfile $company): StreamedResponse
    {
        Gate::authorize('organization.view');
        $column = TenantProfile::IMAGES[$slot] ?? abort(404);

        return CatalogImages::response($company->of($context->tenant())->{$column});
    }
}
