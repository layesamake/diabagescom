<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\AlertCenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Choix personnels de notification : par catégorie, dans l'application et/ou
 * par e-mail. Chacun règle les siens, pour les seules catégories que ses droits
 * lui permettent de voir.
 */
class NotificationController extends Controller
{
    public function edit(Request $request, AlertCenter $alerts, NotificationPreferences $preferences): Response
    {
        /** @var User $user */
        $user = $request->user();
        $categories = $alerts->available($user);

        return Inertia::render('settings/Notifications', [
            'categories' => $categories,
            'preferences' => $preferences->for($user, $categories),
        ]);
    }

    public function update(Request $request, AlertCenter $alerts, NotificationPreferences $preferences): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $categories = $alerts->available($user);

        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.in_app' => ['boolean'],
            'preferences.*.email' => ['boolean'],
        ]);

        $preferences->save($user, $data['preferences'], $categories);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('notifications.saved')]);

        return back();
    }
}
