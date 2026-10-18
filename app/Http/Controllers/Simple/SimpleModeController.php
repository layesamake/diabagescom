<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Audit\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Mode simple — lot 1 « Fondations » : bascule simple / expert et page de
 * comparaison des composants avec les maquettes de référence.
 *
 * La bascule porte sur l'appartenance : un même compte peut être vendeur
 * (mode simple) dans une entreprise et gestionnaire (mode expert) ailleurs.
 */
class SimpleModeController extends Controller
{
    public function __construct()
    {
        // Le mode simple n'existe pas tant qu'il n'est pas activé sur
        // l'environnement : un lot livré sur dev ne peut pas apparaître chez
        // un client en production avant validation.
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function switch(Request $request, TenantContext $context, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([TenantMembership::MODE_SIMPLE, TenantMembership::MODE_EXPERT])],
        ]);

        $membership = $context->membership();
        abort_if($membership === null, 403);

        if ($membership->ui_mode !== $data['mode']) {
            DB::transaction(function () use ($membership, $data, $audit): void {
                $before = ['ui_mode' => $membership->ui_mode];
                $membership->ui_mode = (string) $data['mode'];
                $membership->save();
                $audit->record('membership.ui_mode_changed', $membership, before: $before, after: ['ui_mode' => $membership->ui_mode]);
            });
        }

        return to_route($membership->homeRoute());
    }

    /**
     * Accueil provisoire du mode simple : les écrans arrivent au lot 2.
     */
    public function home(): Response
    {
        return Inertia::render('simple/Accueil');
    }

    /**
     * Page de comparaison : chaque composant à côté de sa maquette.
     * Réservée à l'administrateur, c'est un outil de validation interne.
     */
    public function demo(TenantContext $context): Response
    {
        abort_unless($context->membership()?->isAdmin(), 403);

        return Inertia::render('simple/Demo');
    }

    /** Maquette privée utilisée par la page de comparaison. */
    public function mockup(TenantContext $context, string $fichier): BinaryFileResponse
    {
        abort_unless($context->membership()?->isAdmin(), 403);
        abort_unless((bool) preg_match('/\A[0-9a-z-]+\.png\z/', $fichier), 404);

        $path = resource_path('maquettes/simple/'.$fichier);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
