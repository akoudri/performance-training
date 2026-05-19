<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque la requête si l'utilisateur authentifié n'est pas organisateur.
 * À empiler après `auth:sanctum`.
 */
class EnsureOrganizer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isOrganizer()) {
            return response()->json(
                ['message' => 'Réservé aux comptes organisateurs.'],
                403,
            );
        }

        return $next($request);
    }
}
