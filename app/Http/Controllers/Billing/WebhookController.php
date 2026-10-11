<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\SaasBilling;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Point d'entrée des webhooks des prestataires : pas de session, authentifié
 * uniquement par signature. Rejouer un événement renvoie 200 sans effet.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, SaasBilling $billing): JsonResponse
    {
        [$code, $status] = $billing->handleWebhook($provider, $request);

        return response()->json(['status' => $status], $code);
    }
}
