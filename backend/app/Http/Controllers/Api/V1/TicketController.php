<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tickets de l'utilisateur connecté.
 *
 * @perf-debt: pas de pagination sur /me/tickets — le starter renvoie tous
 *             les tickets du user (le visitor démo en compte ~31, mais un
 *             gros utilisateur peut en avoir des centaines). Cohérent avec
 *             le contrat §8 (rendu massif côté front, génération QR pour
 *             toute la liste). Résolu en branche solution/j2-frontend
 *             (cursor + virtualisation + génération QR à la demande).
 * @perf-debt: query SELECT * FROM tickets WHERE order_id IN (orders du user)
 *             sans eager load → N+1 sur ticketCategory + eventSession dans
 *             la Resource. Résolu en J3.
 */
class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // @perf-debt: get() sans limite — ramène tous les tickets du user
        // en un seul payload (cf. doc-bloc).
        $tickets = Ticket::whereIn(
            'order_id',
            $request->user()->orders()->select('id')
        )
            ->orderBy('id', 'desc')
            ->get();

        return TicketResource::collection($tickets);
    }
}
