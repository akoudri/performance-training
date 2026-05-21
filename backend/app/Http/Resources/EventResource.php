<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @perf-fix: relations `organizer`, `media` et `sessions` ne sont
 *            sérialisées que si elles ont été eager-loadées (whenLoaded).
 *            Le caller contrôle la profondeur via `with([...])`.
 */
class EventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'city' => $this->city,
            'country' => $this->country,
            'venue_name' => $this->venue_name,
            'cover_image_path' => $this->cover_image_path,
            'cover_image_url' => $this->cover_image_path
                ? Storage::disk('s3')->url($this->cover_image_path)
                : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'status' => $this->status,
            'organizer' => new OrganizerResource($this->whenLoaded('organizer')),
            'media' => MediaResource::collection($this->whenLoaded('media')),
            'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
        ];
    }
}
