<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attribue un identifiant de corrélation à chaque requête.
 *
 * L'identifiant est repris de l'en-tête X-Request-Id posé par le proxy s'il est
 * bien formé, sinon généré. Il est ajouté au contexte (logs, jobs mis en file)
 * et renvoyé dans la réponse pour faciliter le support.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        $request->headers->set(self::HEADER, $requestId);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
