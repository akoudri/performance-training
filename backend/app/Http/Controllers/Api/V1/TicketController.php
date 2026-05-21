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
 * @perf-fix: eager loading sur la chaîne `ticketCategory` +
 *           `eventSession.event` pour éviter la cascade N+1 dans
 *           TicketResource.
 * @perf-debt: pas de pagination sur /me/tickets — volume typique <30
 *             tickets par utilisateur, douleur marginale. Hors périmètre
 *             j3-laravel (cf. note "Quand paginer ?" du docs/ateliers/
 *             j3-laravel.md).
 */
class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = Ticket::with(['ticketCategory', 'eventSession.event'])
            ->whereIn(
                'order_id',
                $request->user()->orders()->select('id')
            )
            ->orderBy('id', 'desc')
            ->get();

        return TicketResource::collection($tickets);
    }
}
