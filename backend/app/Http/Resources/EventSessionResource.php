<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @perf-debt: relation `ticketCategories` chargée systématiquement à chaud
 *             (pas de whenLoaded en starter) → N+1 garantie sur la liste de
 *             sessions d'un événement. Résolu en J3 par switch vers
 *             whenLoaded() + with('sessions.ticketCategories') côté caller.
 */
class EventSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'doors_open_at' => $this->doors_open_at?->toIso8601String(),
            'status' => $this->status,
            // @perf-debt: N+1 — chaque session déclenche un SELECT * FROM
            // ticket_categories WHERE event_session_id = ?
            'ticket_categories' => TicketCategoryResource::collection($this->ticketCategories),
        ];
    }
}
