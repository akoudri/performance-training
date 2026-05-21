<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Liste complète des participants (tickets valides) d'un événement.
 *
 * @perf-fix: chaîne `order.user` + `ticketCategory` eager-loadée. Sur
 *           7 000 tickets de l'event star, on passe de ~21 003 SELECT
 *           (1 + 3 × N) à 4 (sessions ids + tickets + orders + users
 *           + categories factorisés via la map Eloquent).
 * @perf-debt: pas d'index sur tickets(event_session_id, created_at desc)
 *             — un événement à 5000 participants charge en seq scan + sort.
 *             Résolu en solution/j3-postgres atelier "postgres-indexes".
 * @perf-debt: pas de pagination — payload massif sur events stars (cf.
 *             contrat §8). La virtualisation côté front (solution/j2-dashboard)
 *             absorbe le DOM, mais le payload reste lourd. Hors périmètre
 *             j3-laravel : la pagination cursor de cette branche cible
 *             /api/v1/events uniquement (cf. note "Quand paginer ?" du
 *             docs/ateliers/j3-laravel.md).
 */
class ParticipantsController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $organizerId = $request->user()->organizers()->first()?->id ?? 0;
        if ($event->organizer_id !== $organizerId) {
            throw new AccessDeniedHttpException("L'événement ne vous appartient pas.");
        }

        $sessionIds = $event->sessions()->pluck('id');

        $query = Ticket::with(['order.user', 'ticketCategory'])
            ->whereIn('event_session_id', $sessionIds)
            ->where('status', Ticket::STATUS_VALID);

        if ($q = $request->string('q')->toString()) {
            $query->where('holder_name', 'ILIKE', "%{$q}%");
        }
        if ($categoryId = $request->integer('category_id')) {
            $query->where('ticket_category_id', $categoryId);
        }

        $tickets = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'data' => ParticipantResource::collection($tickets),
        ]);
    }
}
