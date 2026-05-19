<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @perf-debt: relations `organizer` et `media` lues sans whenLoaded() —
 *             chaque event listé déclenche 2 SELECT supplémentaires
 *             (organizer + media). Résolu en J3 par switch vers
 *             whenLoaded() + with(['organizer', 'media']) côté caller.
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
            // @perf-debt: N+1 — chaque event listé déclenche 1 SELECT
            // organizer + 1 SELECT media (puis N requêtes par media row).
            'organizer' => new OrganizerResource($this->organizer),
            'media' => MediaResource::collection($this->media),
        ];
    }
}
