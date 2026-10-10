<?php

namespace App\Http\Controllers;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « Abonnement » : toujours accessible, même suspendu, pour régulariser.
 * Le paiement en ligne arrive avec EPIC-08 ; d'ici là, régularisation via le support.
 */
class SubscriptionController extends Controller
{
    public function show(TenantContext $context, FeatureAccess $access): Response
    {
        $tenant = $context->tenant();

        return Inertia::render('settings/Subscription', [
            'subscription' => $access->summary($tenant),
            'limits' => [
                'warehouses' => [
                    'usage' => $access->usage($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
                    'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_WAREHOUSES),
                ],
                'users' => [
                    'usage' => $access->usage($tenant, FeatureAccess::RESOURCE_USERS),
                    'limit' => $access->limit($tenant, FeatureAccess::RESOURCE_USERS),
                ],
            ],
            'plans' => Plan::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get(['code', 'name', 'warehouse_limit', 'included_users', 'accounting_enabled', 'price_monthly', 'price_yearly']),
        ]);
    }
}
