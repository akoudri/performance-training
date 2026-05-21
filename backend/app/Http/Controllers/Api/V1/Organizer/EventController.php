<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\StoreEventRequest;
use App\Http\Requests\Organizer\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD événements pour l'organisateur.
 *
 * @perf-fix: eager loading via `with([...])` sur les relations exposées
 *           par EventResource. Plus de N+1 sur organizer/media.
 */
class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizerId = $this->organizerId($request);

        $events = Event::with([
            'organizer',
            'media' => fn ($q) => $q->orderBy('position'),
        ])
            ->where('organizer_id', $organizerId)
            ->orderBy('updated_at', 'desc')
            ->get();

        return EventResource::collection($events);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $organizerId = $this->organizerId($request);

        $title = $request->string('title');
        $event = Event::create([
            ...$request->validated(),
            'organizer_id' => $organizerId,
            'slug' => Str::slug($title).'-'.now()->timestamp,
            'country' => $request->string('country', 'FR'),
            'status' => $request->string('status', Event::STATUS_DRAFT),
            'published_at' => $request->string('status') === Event::STATUS_PUBLISHED ? now() : null,
        ]);
        $event->load(['organizer', 'media' => fn ($q) => $q->orderBy('position')]);

        $this->invalidateCaches($event);

        return (new EventResource($event))->response()->setStatusCode(201);
    }

    public function show(Request $request, Event $event): EventResource
    {
        $this->authorizeOwnership($request, $event);
        $event->load([
            'organizer',
            'media' => fn ($q) => $q->orderBy('position'),
            'sessions.ticketCategories',
        ]);

        return new EventResource($event);
    }

    public function update(UpdateEventRequest $request, Event $event): EventResource
    {
        $this->authorizeOwnership($request, $event);

        $event->fill($request->validated());
        if ($request->string('status') === Event::STATUS_PUBLISHED && $event->published_at === null) {
            $event->published_at = now();
        }
        $event->save();
        $event->load(['organizer', 'media' => fn ($q) => $q->orderBy('position')]);

        $this->invalidateCaches($event);

        return new EventResource($event);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorizeOwnership($request, $event);

        // Soft-archive plutôt que delete (cf. spec §6 "DELETE → Archiver").
        $event->update(['status' => Event::STATUS_ARCHIVED]);

        $this->invalidateCaches($event);

        return response()->json(null, 204);
    }

    /**
     * Invalide le cache des endpoints publics impactés par un write organizer.
     *
     * - `events` : index public + listings filtrés + show + sessions (tous
     *   les entries tagués `events` partent en bloc — coarse mais simple).
     * - `organizer:{id}` : KPIs et sales-chart du dashboard de l'organizer
     *   propriétaire (active_events change sur publish/archive).
     *
     * Pourquoi tag-flush et pas key-by-key : la liste des clés possibles
     * (filtres q/city/category/from sur l'index) est non bornée. Le tag
     * permet une invalidation O(1) côté Redis.
     */
    private function invalidateCaches(Event $event): void
    {
        Cache::tags(['events'])->flush();
        Cache::tags(["organizer:{$event->organizer_id}"])->flush();
    }

    private function organizerId(Request $request): int
    {
        return $request->user()->organizers()->first()?->id ?? 0;
    }

    private function authorizeOwnership(Request $request, Event $event): void
    {
        if ($event->organizer_id !== $this->organizerId($request)) {
            throw new AccessDeniedHttpException("L'événement ne vous appartient pas.");
        }
    }
}
