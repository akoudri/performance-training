<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @perf-debt: relation `tickets` lue sans whenLoaded() → 1 SELECT, puis
 *             la cascade N+1 dans TicketResource.
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'total_cents' => $this->total_cents,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'created_at' => $this->created_at?->toIso8601String(),
            'tickets' => TicketResource::collection($this->tickets),
        ];
    }
}
