<?php

namespace App\Http\Controllers\Simple;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 6 : parcours de démarrage tactile.
 *
 * Les étapes et leur état viennent de StartGuide, déjà partagé par Inertia.
 * Cela conserve la même progression, les mêmes permissions et les mêmes
 * détections automatiques que le guide expert.
 */
class SimpleGuideController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(): Response
    {
        return Inertia::render('simple/PremiersPas');
    }
}
