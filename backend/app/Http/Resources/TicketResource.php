<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @perf-debt: relations ticketCategory + eventSession + event (via session)
 *             lues sans whenLoaded() → cascade de N+1 sur la liste tickets.
 *             Résolu en J3.
 */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'holder_name' => $this->holder_name,
            'status' => $this->status,
            'pdf_path' => $this->pdf_path,
            // @perf-debt: 3 SELECT par ticket (category, session, session.event).
            'ticket_category' => new TicketCategoryResource($this->ticketCategory),
            'event_session' => [
                'id' => $this->eventSession->id,
                'starts_at' => $this->eventSession->starts_at?->toIso8601String(),
                'event' => [
                    'id' => $this->eventSession->event->id,
                    'slug' => $this->eventSession->event->slug,
                    'title' => $this->eventSession->event->title,
                ],
            ],
        ];
    }
}
