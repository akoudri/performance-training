<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @perf-fix: relations sérialisées uniquement quand eager-loadées
 *            (whenLoaded). Caller doit poser `with(['ticketCategory',
 *            'eventSession.event'])` sur la query qui sert cette Resource.
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
            'ticket_category' => new TicketCategoryResource($this->whenLoaded('ticketCategory')),
            'event_session' => $this->whenLoaded('eventSession', fn () => [
                'id' => $this->eventSession->id,
                'starts_at' => $this->eventSession->starts_at?->toIso8601String(),
                'event' => $this->eventSession->relationLoaded('event') ? [
                    'id' => $this->eventSession->event->id,
                    'slug' => $this->eventSession->event->slug,
                    'title' => $this->eventSession->event->title,
                ] : null,
            ]),
        ];
    }
}
