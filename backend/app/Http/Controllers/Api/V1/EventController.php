<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventSessionResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste / fiche / sessions d'événements (public).
 *
 * **starter** : aucun `with()`, aucun cache. Les Resources (étape 10)
 * traverseront les relations à chaud → N+1 sur organizer + media + sessions.
 *
 * @perf-debt: pas de pagination sur la liste — le starter renvoie tous les
 *             events publiés (jusqu'à 1 200 sur le seed réaliste). Cohérent
 *             avec le contrat §8 (rendu massif côté front pour exposer la
 *             dégradation visuelle / réseau / DOM). Résolu en branche
 *             solution/j2-frontend (cursor + scroll virtualisé).
 * @perf-debt: N+1 sur events.organizer / events.media / events.sessions
 *             — résolu en J3 atelier "laravel-eager-loading".
 * @perf-debt: pas de Cache::remember sur la home et les listes
 *             — résolu en J3 atelier "laravel-redis-cache".
 */
class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Event::query()
            ->where('status', Event::STATUS_PUBLISHED);

        if ($q = $request->string('q')->toString()) {
            // @perf-debt: ILIKE sans index FTS — résolu J3 (gin tsvector FR).
            $query->where(function ($w) use ($q) {
                $w->where('title', 'ILIKE', "%{$q}%")
                    ->orWhere('description', 'ILIKE', "%{$q}%");
            });
        }
        if ($city = $request->string('city')->toString()) {
            $query->where('city', $city);
        }
        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }
        if ($from = $request->date('from')) {
            $query->where('published_at', '>=', $from);
        }

        // @perf-debt: get() sans limite ni pagination — ramène tous les
        // events filtrés en un seul payload (cf. doc-bloc).
        $events = $query->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => EventResource::collection($events),
        ]);
    }

    public function show(string $slug): EventResource
    {
        // @perf-debt: pas de with() — toutes les relations qui seront lues
        // dans la Resource (organizer, media, sessions, ticketCategories)
        // déclenchent une requête supplémentaire chacune.
        $event = Event::where('slug', $slug)->firstOrFail();

        return new EventResource($event);
    }

    public function sessions(string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->firstOrFail();

        // @perf-debt: pas de with('sessions.ticketCategories') — chaque
        // session déclenche un SELECT pour ses categories.
        return response()->json([
            'data' => EventSessionResource::collection($event->sessions),
        ]);
    }
}
