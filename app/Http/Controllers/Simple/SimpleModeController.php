<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Audit\AuditLog;
use App\Domain\Notifications\AlertCenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Sales\SalesDayLedger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     * Accueil du mode simple : chiffres du jour du vendeur (pas un rapport
     * de gestion — tout rôle qui vend voit toujours ses propres ventes),
     * tuiles d'action selon ses permissions, alertes partagées avec le
     * tableau de bord expert.
     */
    public function home(
        Request $request,
        TenantContext $context,
        SalesDayLedger $ledger,
        AlertCenter $center,
        NotificationPreferences $preferences,
    ): Response {
        $membership = $context->membership();
        abort_if($membership === null, 403);

        /** @var User $user */
        $user = $request->user();
        $all = Gate::allows('report.view_all');
        $today = CarbonImmutable::today();

        $pending = $center->pending($user);
        $visible = array_keys(array_filter($preferences->for($user, array_keys($pending)), fn (array $p) => $p['in_app']));

        return Inertia::render('simple/Accueil', [
            'figures' => [
                'today' => $ledger->totalsForDay($today, $user, $all),
                'yesterday' => $ledger->totalsForDay($today->subDay(), $user, $all),
            ],
            'tiles' => array_values(array_filter([
                Gate::allows('sale.create') && Gate::allows('sale.validate') ? 'sell' : null,
                Gate::any(['cash.view_own', 'cash.view_all']) ? 'cash' : null,
                Gate::allows('inventory.view') ? 'stock' : null,
                Gate::allows('customer.view') ? 'customers' : null,
            ])),
            'alerts' => array_intersect_key($pending, array_flip($visible)),
        ]);
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
        abort_unless((bool) preg_match('/\A[0-9a-z-]+\z/', $fichier), 404);

        $path = resource_path('maquettes/simple/'.$fichier.'.png');
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
