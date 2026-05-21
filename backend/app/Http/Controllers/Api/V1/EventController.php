<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventSessionResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Liste / fiche / sessions d'événements (public).
 *
 * @perf-fix: eager loading via `with([...])` sur toutes les relations
 *           lues par les Resources. Index passe de 49 à 3 requêtes
 *           sur le dataset réaliste (1 200 events publiés).
 * @perf-fix: hot path en cache Redis via `Cache::tags(['events',
 *           "event:{$slug}"])->remember(...)`. TTL court (60 s liste,
 *           300 s fiche/sessions). Invalidation depuis les writes
 *           organizer (cf. Organizer\EventController).
 * @perf-fix: pagination cursor sur /events (per_page = 20). Réponse
 *           enrichie d'un bloc `meta.{next_cursor,prev_cursor,per_page}`.
 *           Le payload home passe de ~4 Mo (1 200 events) à ~80 Ko.
 */
class EventController extends Controller
{
    private const TTL_INDEX = 60;

    private const TTL_DETAIL = 300;

    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'q' => $request->string('q')->toString(),
            'city' => $request->string('city')->toString(),
            'category' => $request->string('category')->toString(),
            'from' => $request->date('from')?->toDateString(),
        ];
        $cursor = $request->string('cursor')->toString();
        $cacheKey = 'events:index:'.md5(serialize($filters).'|cursor='.$cursor);

        // On sérialise dans le cache la *réponse* prête à émettre, pas le
        // CursorPaginator brut : Laravel 11+ pose
        // `cache.serializable_classes = false` par défaut (prévention gadget
        // chain). Cacher des objets Eloquent / Paginator déclencherait
        // `__PHP_Incomplete_Class` au reload.
        $payload = Cache::tags(['events'])->remember(
            $cacheKey,
            self::TTL_INDEX,
            function () use ($filters) {
                $query = Event::query()
                    ->with([
                        'organizer',
                        'media' => fn ($q) => $q->orderBy('position'),
                    ])
                    ->where('status', Event::STATUS_PUBLISHED);

                if ($filters['q'] !== '') {
                    // @perf-debt: ILIKE sans index FTS — résolu
                    // solution/j3-postgres (gin tsvector FR).
                    $query->where(function ($w) use ($filters) {
                        $w->where('title', 'ILIKE', "%{$filters['q']}%")
                            ->orWhere('description', 'ILIKE', "%{$filters['q']}%");
                    });
                }
                if ($filters['city'] !== '') {
                    $query->where('city', $filters['city']);
                }
                if ($filters['category'] !== '') {
                    $query->where('category', $filters['category']);
                }
                if ($filters['from'] !== null) {
                    $query->where('published_at', '>=', $filters['from']);
                }

                $paginator = $query->orderBy('published_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->cursorPaginate(self::PER_PAGE);

                return [
                    'data' => EventResource::collection($paginator->items())
                        ->resolve(),
                    'meta' => [
                        'next_cursor' => $paginator->nextCursor()?->encode(),
                        'prev_cursor' => $paginator->previousCursor()?->encode(),
                        'per_page' => $paginator->perPage(),
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function show(string $slug): JsonResponse
    {
        $payload = Cache::tags(['events', "event:{$slug}"])->remember(
            "events:show:{$slug}",
            self::TTL_DETAIL,
            function () use ($slug) {
                $event = Event::with([
                    'organizer',
                    'media' => fn ($q) => $q->orderBy('position'),
                    'sessions.ticketCategories',
                ])
                    ->where('slug', $slug)
                    ->firstOrFail();

                return ['data' => (new EventResource($event))->resolve()];
            }
        );

        return response()->json($payload);
    }

    public function sessions(string $slug): JsonResponse
    {
        $payload = Cache::tags(['events', "event:{$slug}"])->remember(
            "events:sessions:{$slug}",
            self::TTL_DETAIL,
            function () use ($slug) {
                $event = Event::where('slug', $slug)->firstOrFail();
                $event->load('sessions.ticketCategories');

                return [
                    'data' => EventSessionResource::collection($event->sessions)->resolve(),
                ];
            }
        );

        return response()->json($payload);
    }
}
