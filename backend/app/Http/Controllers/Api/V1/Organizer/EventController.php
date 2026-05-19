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
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD événements pour l'organisateur.
 *
 * @perf-debt: pas de pagination sur la liste — le starter renvoie tous les
 *             events de l'organizer (jusqu'à 75 sur le seed réaliste, peut
 *             monter avec le temps). Résolu en branche solution/j2-frontend
 *             (cursor + UI paginée).
 * @perf-debt: pas de with() sur sessions/media — N+1 dans les Resources.
 *             Résolu en J3.
 */
class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizerId = $this->organizerId($request);

        // @perf-debt: get() sans limite — ramène tous les events de
        // l'organizer en un seul payload (cf. doc-bloc).
        $events = Event::where('organizer_id', $organizerId)
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

        return (new EventResource($event))->response()->setStatusCode(201);
    }

    public function show(Request $request, Event $event): EventResource
    {
        $this->authorizeOwnership($request, $event);

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

        return new EventResource($event);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorizeOwnership($request, $event);

        // Soft-archive plutôt que delete (cf. spec §6 "DELETE → Archiver").
        $event->update(['status' => Event::STATUS_ARCHIVED]);

        return response()->json(null, 204);
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
