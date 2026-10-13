<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages publiques : accueil (tarifs lus en base, donc à jour dès qu'une formule
 * est modifiée dans la console), plan du site et consignes aux robots.
 */
class WelcomeController extends Controller
{
    public const TITLE = 'Ventes, stock et caisse pour votre commerce';

    public const DESCRIPTION = 'Logiciel de gestion commerciale pour les commerces et PME du Sénégal : ventes, caisse, stock multi-dépôts, achats, factures PDF à votre logo, WhatsApp, boutique en ligne et comptabilité SYSCOHADA, même sans connexion.';

    public function __invoke(): Response
    {
        $plans = Plan::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'warehouse_limit' => $plan->warehouse_limit,
                'included_users' => $plan->included_users,
                'accounting_enabled' => $plan->accounting_enabled,
                'online_store_enabled' => $plan->online_store_enabled,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
            ]);

        /** @var array<string, array{label: string, price_monthly: int|null, price_yearly: int|null}> $configured */
        $configured = config('diaba.addons', []);

        $addons = collect($configured)
            ->filter(fn (array $addon): bool => $addon['price_monthly'] !== null)
            ->map(fn (array $addon, string $code): array => [
                'code' => $code,
                'label' => $addon['label'],
                'price_monthly' => $addon['price_monthly'],
                'price_yearly' => $addon['price_yearly'],
            ])
            ->values();

        $whatsapp = preg_replace('/\D/', '', (string) config('diaba.marketing.whatsapp'));

        return Inertia::render('Welcome', [
            'plans' => $plans,
            'addons' => $addons,
            'recommended' => config('diaba.subscriptions.default_plan'),
            'trialDays' => (int) config('diaba.subscriptions.trial_days'),
            'contact' => [
                'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                'email' => config('diaba.marketing.email') ?: null,
            ],
            'testimonials' => array_values(config('diaba.marketing.testimonials', [])),
        ])->withViewData([
            'meta' => [
                'title' => self::TITLE.' - '.config('app.name'),
                'description' => self::DESCRIPTION,
                'canonical' => url('/'),
            ],
        ]);
    }

    public function sitemap(): HttpResponse
    {
        $urls = [
            ['loc' => url('/'), 'priority' => '1.0'],
            ['loc' => route('register'), 'priority' => '0.8'],
            ['loc' => route('legal.privacy'), 'priority' => '0.3'],
            ['loc' => route('legal.terms'), 'priority' => '0.3'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc><priority>'.$url['priority'].'</priority></url>'."\n";
        }

        return response($xml."</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Hors production, tout est interdit aux robots (dev.diaba.cloud ne doit pas être indexé). */
    public function robots(): HttpResponse
    {
        $body = config('diaba.marketing.indexable')
            ? "User-agent: *\nDisallow: /dashboard\nDisallow: /platform\nDisallow: /settings\n\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
