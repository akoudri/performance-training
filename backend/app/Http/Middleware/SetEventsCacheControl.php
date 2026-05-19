<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose des en-têtes Cache-Control sur les endpoints publics de découverte
 * d'événements (solution/j1-cdn-cache).
 *
 * Politique :
 *  - Liste `/events`           : public, max-age=60,  s-maxage=60,  stale-while-revalidate=300
 *  - Fiche `/events/{slug}`    : public, max-age=300, s-maxage=300, stale-while-revalidate=900
 *  - Sessions `/events/{slug}/sessions` : idem fiche.
 *
 * Le `s-maxage` n'a d'effet que si un cache partagé (CDN / reverse proxy)
 * est placé en amont — c'est le « hook CDN » du substrat starter Phase 4-bis,
 * sur lequel J1 ne pose que les **headers** (pas de proxy_cache Nginx ;
 * ISR Nitro porte le cache applicatif côté Nuxt SSR).
 *
 * Discrimination liste / fiche par la présence d'un segment d'URI au-delà
 * de `/events` : la liste est `/api/v1/events` (sans suffixe).
 */
class SetEventsCacheControl
{
    private const TTL_LIST_SECONDS    = 60;
    private const SWR_LIST_SECONDS    = 300;
    private const TTL_DETAIL_SECONDS  = 300;
    private const SWR_DETAIL_SECONDS  = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ne pose les headers que sur les réponses succès, et uniquement pour
        // GET / HEAD (HEAD doit renvoyer les mêmes headers que GET, par RFC).
        // Évite de polluer une 404 / 500 / réponse d'erreur de validation.
        if (! $request->isMethodSafe() || $response->getStatusCode() !== 200) {
            return $response;
        }

        // `/api/v1/events`           → liste
        // `/api/v1/events/{slug}`    → fiche (et `/sessions`) → détail
        $isList = $request->is('api/v1/events');

        if ($isList) {
            $maxAge = self::TTL_LIST_SECONDS;
            $swr    = self::SWR_LIST_SECONDS;
        } else {
            $maxAge = self::TTL_DETAIL_SECONDS;
            $swr    = self::SWR_DETAIL_SECONDS;
        }

        $response->headers->set(
            'Cache-Control',
            sprintf(
                'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
                $maxAge,
                $maxAge,
                $swr,
            ),
        );

        return $response;
    }
}
