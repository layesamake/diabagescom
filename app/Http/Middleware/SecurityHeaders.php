<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité applicatifs : politique de contenu (CSP) avec nonce pour les
 * scripts, qui bloque l'exécution de tout script injecté (XSS) et l'encadrement de
 * l'application dans un site tiers. Les en-têtes X-Frame-Options, nosniff,
 * Referrer-Policy et Permissions-Policy sont posés par Nginx. HSTS est posé aussi ici
 * (HTTPS uniquement, jamais en local) : le Caddy de bordure de production ne l'envoyait
 * pas, et un en-tête identique posé par Caddy remplace simplement celui-ci.
 *
 * Une réponse qui porte déjà sa propre politique (images, PDF) est laissée telle quelle.
 * En local, Vite sert les modules à la volée : pas de CSP.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = app()->environment('local') ? null : Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        if ($nonce !== null && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // 'wasm-unsafe-eval' : lecteur de codes-barres (WebAssembly) ; aucun eval JavaScript.
                "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "worker-src 'self'",
                "manifest-src 'self'",
                "media-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Sans « preload » : l'inscription dans la liste des navigateurs est difficile à annuler.
        if ($request->isSecure() && ! app()->environment('local')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
