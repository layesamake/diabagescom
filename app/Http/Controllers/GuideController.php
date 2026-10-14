<?php

namespace App\Http\Controllers;

use App\Domain\Onboarding\StartGuide;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Guide de démarrage : page complète, étapes cochées ou passées, carte de
 * l'accueil et visite. Les étapes elles-mêmes viennent de la prop partagée
 * `guide` (HandleInertiaRequests) ; chacun ne gère que sa propre progression.
 */
class GuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('guide/Index');
    }

    public function step(Request $request, string $step, StartGuide $guide): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([StartGuide::DONE, StartGuide::SKIPPED, StartGuide::TODO])]]);

        /** @var User $user */
        $user = $request->user();

        try {
            $guide->mark($user, $step, $data['status']);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return back(303);
    }

    public function update(Request $request, StartGuide $guide): RedirectResponse
    {
        $data = $request->validate([
            'dismissed' => ['sometimes', 'boolean'],
            'tour_seen' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $guide->setState($user, $data['dismissed'] ?? null, $data['tour_seen'] ?? null);

        return back(303);
    }
}
