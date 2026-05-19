<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_cents' => $this->price_cents,
            'quota' => $this->quota,
            'sold' => $this->sold,
            'remaining' => max(0, $this->quota - $this->sold),
        ];
    }
}
