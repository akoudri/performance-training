<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @perf-fix: `ticketCategories` n'est sérialisée que si eager-loadée.
 *            Caller doit poser `with('sessions.ticketCategories')`.
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
            'ticket_categories' => TicketCategoryResource::collection($this->whenLoaded('ticketCategories')),
        ];
    }
}
