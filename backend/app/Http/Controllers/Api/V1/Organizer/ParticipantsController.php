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
 * @perf-debt: pas de pagination — payload massif sur events stars
 *             (jusqu'à 8 000 lignes pour les events stars du seed
 *             réaliste). En production, paginer côté API + virtualiser
 *             côté front. Résolu en branche solution/j2-dashboard
 *             (pagination cursor) + solution/j2-frontend
 *             (vue-virtual-scroller).
 * @perf-debt: pas d'index sur tickets(event_session_id, created_at desc)
 *             — un événement à 5000 participants charge en seq scan + sort.
 *             Résolu en J3 atelier "postgres-indexes".
 * @perf-debt: pas de with('ticketCategory') ni de with('order.user') →
 *             la Resource déclenchera N+1 pour chaque ligne. Résolu en J3.
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

        $query = Ticket::whereIn('event_session_id', $sessionIds)
            ->where('status', Ticket::STATUS_VALID);

        if ($q = $request->string('q')->toString()) {
            $query->where('holder_name', 'ILIKE', "%{$q}%");
        }
        if ($categoryId = $request->integer('category_id')) {
            $query->where('ticket_category_id', $categoryId);
        }

        // @perf-debt: get() sans limite — ramène TOUS les tickets de
        // l'event en un seul payload pour rendre le v-for non virtualisé
        // démonstratif côté front (cf. spec §8 / §5 écran 6).
        $tickets = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'data' => ParticipantResource::collection($tickets),
        ]);
    }
}
