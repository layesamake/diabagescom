<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Subscriptions\FeatureToggle;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activation de la comptabilité et de la boutique en ligne : incluses dans
 * toutes les formules mais désactivées par défaut, actionnables uniquement
 * par l'administrateur de l'entreprise (docs validés le 17/10/2026).
 */
class FeatureController extends Controller
{
    public function edit(TenantContext $context, FeatureAccess $access): Response
    {
        $tenant = $context->tenant();
        abort_unless($context->membership()?->isAdmin(), 403);

        return Inertia::render('settings/Features', [
            'features' => [
                FeatureAccess::FEATURE_ACCOUNTING => [
                    'included' => $access->planIncludesFeature($tenant, FeatureAccess::FEATURE_ACCOUNTING),
                    'activated' => $tenant->accounting_activated,
                ],
                FeatureAccess::FEATURE_ONLINE_STORE => [
                    'included' => $access->planIncludesFeature($tenant, FeatureAccess::FEATURE_ONLINE_STORE),
                    'activated' => $tenant->online_store_activated,
                ],
            ],
        ]);
    }

    public function update(Request $request, string $feature, TenantContext $context, FeatureToggle $toggle): RedirectResponse
    {
        abort_unless($context->membership()?->isAdmin(), 403);
        abort_unless(in_array($feature, [FeatureAccess::FEATURE_ACCOUNTING, FeatureAccess::FEATURE_ONLINE_STORE], true), 404);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();
        $toggle->setEnabled($context->tenant(), $feature, (bool) $data['enabled'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __($data['enabled'] ? 'subscription.feature_activated' : 'subscription.feature_deactivated')]);

        return back(303);
    }
}
